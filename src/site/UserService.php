<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

/**
 * Dashboard accounts (PRD §6.3): first-run admin, password login with
 * throttling. Passwords are stored as Argon2id hashes.
 */
#[Service]
class UserService {

    public const MIN_PASSWORD_LENGTH = 10;
    public const MAX_FAILURES = 10;
    public const LOCK_SECONDS = 900;

    #[Autowired]
    private PdbcTemplate $db;

    /** @var array<string, array{0: int, 1: int}> email => [failures, first failure time] (per worker) */
    private array $failures = [];

    private ?string $dummyHash = null;

    public function needsSetup(): bool {
        return (int) $this->db->queryForScalar('SELECT count(*) FROM users') === 0;
    }

    /** Creates the first (admin) user. Fails once any user exists. */
    public function createFirstAdmin(string $email, string $name, string $password): User {
        $email = self::email($email);
        self::checkPassword($password);
        // queryForList: the conditional INSERT returns no row once a user exists.
        $id = $this->db->queryForList(
            'INSERT INTO users (email, name, password_hash, is_admin)
             SELECT ?, ?, ?, TRUE WHERE NOT EXISTS (SELECT 1 FROM users) RETURNING id',
            [$email, mb_substr(trim($name), 0, 255), password_hash($password, PASSWORD_ARGON2ID)]
        )[0]['id'] ?? null;
        if ($id === null) {
            throw new InvalidInput('setup is already complete; sign in instead');
        }
        return $this->find((int) $id);
    }

    /** Returns the user on success; null for a wrong email or password (never says which). */
    public function authenticate(string $email, string $password): ?User {
        $key = strtolower(trim($email));
        [$count, $since] = $this->failures[$key] ?? [0, 0];
        if ($count >= self::MAX_FAILURES && time() - $since < self::LOCK_SECONDS) {
            throw new InvalidInput('too many failed sign-ins; try again in 15 minutes');
        }
        $row = $this->db->queryForList('SELECT id, password_hash FROM users WHERE email = ?', [$key])[0] ?? null;
        // Verify against a dummy hash for unknown emails, so timing does not reveal accounts.
        $hash = $row['password_hash'] ?? ($this->dummyHash ??= password_hash(random_bytes(16), PASSWORD_ARGON2ID));
        if (!password_verify($password, (string) $hash) || $row === null) {
            $this->failures[$key] = [$count + 1, $count === 0 ? time() : $since];
            return null;
        }
        unset($this->failures[$key]);
        $this->db->update('UPDATE users SET last_login_at = now() WHERE id = ?', [$row['id']]);
        return $this->find((int) $row['id']);
    }

    public function find(int $id): ?User {
        $row = $this->db->queryForList('SELECT id, email, name, is_admin FROM users WHERE id = ?', [$id])[0] ?? null;
        if ($row === null) {
            return null;
        }
        $siteIds = array_map('intval', array_column(
            $this->db->queryForList('SELECT site_id FROM site_users WHERE user_id = ?', [$id]), 'site_id'));
        $admin = $row['is_admin'] === true || $row['is_admin'] === 't' || $row['is_admin'] === 1;
        return new User((int) $row['id'], (string) $row['email'], (string) $row['name'], $admin, $siteIds);
    }

    public static function email(string $email): string {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new InvalidInput('enter a valid email address');
        }
        return $email;
    }

    public static function checkPassword(string $password): void {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidInput('passwords need at least ' . self::MIN_PASSWORD_LENGTH . ' characters');
        }
    }
}
