<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\suvera\snowprint\infra\RateLimiter;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\stereotype\Transactional;

/**
 * Dashboard accounts (PRD §6.3): first-run admin, invited users, password
 * login with throttling, per-site roles. Passwords are stored as Argon2id
 * hashes.
 */
#[Service]
class UserService {

    public const MIN_PASSWORD_LENGTH = 10;
    public const MAX_FAILURES = 10;
    public const LOCK_SECONDS = 900;

    #[Autowired]
    private PdbcTemplate $db;

    #[Autowired]
    private RateLimiter $limiter;

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
        $bucket = 'login:' . hash('sha256', $key); // never the address itself
        if ($this->limiter->hits($bucket, self::LOCK_SECONDS) >= self::MAX_FAILURES) {
            throw new InvalidInput('too many failed sign-ins; try again in 15 minutes');
        }
        $row = $this->db->queryForList('SELECT id, password_hash FROM users WHERE email = ?', [$key])[0] ?? null;
        // Verify against a dummy hash for unknown emails, so timing does not reveal accounts.
        $hash = $row['password_hash'] ?? ($this->dummyHash ??= password_hash(random_bytes(16), PASSWORD_ARGON2ID));
        if (!password_verify($password, (string) $hash) || $row === null) {
            $this->limiter->hit($bucket, self::LOCK_SECONDS);
            return null;
        }
        $this->limiter->clear($bucket);
        $this->db->update('UPDATE users SET last_login_at = now() WHERE id = ?', [$row['id']]);
        return $this->find((int) $row['id']);
    }

    public function find(int $id): ?User {
        $row = $this->db->queryForList('SELECT id, email, name, is_admin FROM users WHERE id = ?', [$id])[0] ?? null;
        if ($row === null) {
            return null;
        }
        $roles = [];
        foreach ($this->db->queryForList('SELECT site_id, role FROM site_users WHERE user_id = ?', [$id]) as $r) {
            $roles[(int) $r['site_id']] = (string) $r['role'];
        }
        return new User((int) $row['id'], (string) $row['email'], (string) $row['name'], self::bool($row['is_admin']), $roles);
    }

    /**
     * Every user with their site roles (by domain), for the users page.
     * @return list<array{id: int, email: string, name: string, is_admin: bool, sites: array<string, string>, last_login_at: ?string}>
     */
    public function all(): array {
        $roles = [];
        foreach ($this->db->queryForList(
            'SELECT su.user_id, s.domain, su.role FROM site_users su JOIN sites s ON s.id = su.site_id ORDER BY s.domain') as $r) {
            $roles[(int) $r['user_id']][(string) $r['domain']] = (string) $r['role'];
        }
        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'name' => (string) $r['name'],
            'is_admin' => self::bool($r['is_admin']),
            'sites' => (object) ($roles[(int) $r['id']] ?? []),
            'last_login_at' => $r['last_login_at'] === null ? null : (string) $r['last_login_at'],
        ], $this->db->queryForList('SELECT id, email, name, is_admin, last_login_at FROM users ORDER BY email'));
    }

    /**
     * Sets a user's instance-admin flag and site roles (replacing all of them).
     * The last admin cannot lose admin rights.
     * @param array<int, string> $siteRoles site id => role
     */
    #[Transactional]
    public function updateAccess(int $userId, bool $isAdmin, array $siteRoles): User {
        $user = $this->find($userId) ?? throw new InvalidInput('unknown user');
        self::checkRoles($siteRoles);
        if ($user->isAdmin && !$isAdmin && $this->adminCount() <= 1) {
            throw new InvalidInput('the last admin cannot lose admin rights');
        }
        $this->db->update('UPDATE users SET is_admin = ? WHERE id = ?', [$isAdmin ? 'true' : 'false', $userId]);
        $this->db->update('DELETE FROM site_users WHERE user_id = ?', [$userId]);
        $this->grant($userId, $siteRoles);
        return $this->find($userId);
    }

    /** Deletes a user; never yourself or the last admin. Their sign-in sessions stop working at once. */
    public function delete(User $actor, int $userId): void {
        if ($actor->id === $userId) {
            throw new InvalidInput('you cannot delete your own account');
        }
        $user = $this->find($userId) ?? throw new InvalidInput('unknown user');
        if ($user->isAdmin && $this->adminCount() <= 1) {
            throw new InvalidInput('the last admin cannot be deleted');
        }
        $this->db->update('DELETE FROM users WHERE id = ?', [$userId]);
    }

    /** Creates a user from an accepted invite. */
    public function createInvited(string $email, string $name, string $password, bool $isAdmin, array $siteRoles): User {
        $email = self::email($email);
        self::checkPassword($password);
        if ((int) $this->db->queryForScalar('SELECT count(*) FROM users WHERE email = ?', [$email]) > 0) {
            throw new InvalidInput('an account with this email already exists; sign in instead');
        }
        $id = (int) $this->db->queryForScalar(
            'INSERT INTO users (email, name, password_hash, is_admin) VALUES (?, ?, ?, ?) RETURNING id',
            [$email, mb_substr(trim($name), 0, 255), password_hash($password, PASSWORD_ARGON2ID), $isAdmin ? 'true' : 'false']
        );
        $this->grant($id, $siteRoles);
        return $this->find($id);
    }

    /** @param array<int, string> $siteRoles */
    public static function checkRoles(array $siteRoles): void {
        foreach ($siteRoles as $siteId => $role) {
            if (!is_int($siteId) || !in_array($role, User::ROLES, true)) {
                throw new InvalidInput('site roles are "viewer" or "admin"');
            }
        }
    }

    /** @param array<int, string> $siteRoles grants for sites that (still) exist */
    private function grant(int $userId, array $siteRoles): void {
        foreach ($siteRoles as $siteId => $role) {
            $this->db->update('INSERT INTO site_users (site_id, user_id, role) SELECT id, ?, ? FROM sites WHERE id = ?',
                [$userId, $role, $siteId]);
        }
    }

    private function adminCount(): int {
        return (int) $this->db->queryForScalar('SELECT count(*) FROM users WHERE is_admin');
    }

    private static function bool(mixed $value): bool {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
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
