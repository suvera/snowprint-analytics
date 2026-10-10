<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\stereotype\Transactional;

/**
 * Invite links (SP-019). An admin invites an email address with an admin flag
 * and site roles; the link carries a random token (only its SHA-256 digest is
 * stored) and works once, for INVITE_DAYS days. The invitee picks a name and
 * password. The link is emailed when SMTP is configured; the admin can always
 * copy it.
 */
#[Service]
class InviteService {

    public const INVITE_DAYS = 7;

    #[Autowired]
    private PdbcTemplate $db;

    #[Autowired]
    private UserService $users;

    /**
     * @param array<int, string> $siteRoles site id => role
     * @return array{id: int, token: string, email: string, expires_at: string}
     */
    public function create(User $actor, string $email, bool $isAdmin, array $siteRoles): array {
        $email = UserService::email($email);
        UserService::checkRoles($siteRoles);
        if (!$isAdmin && $siteRoles === []) {
            throw new InvalidInput('give the user at least one site, or make them an admin');
        }
        if ((int) $this->db->queryForScalar('SELECT count(*) FROM users WHERE email = ?', [$email]) > 0) {
            throw new InvalidInput('a user with this email already exists');
        }
        $token = bin2hex(random_bytes(24));
        $row = $this->db->queryForList(
            "INSERT INTO invites (email, token_hash, is_admin, site_roles, created_by, expires_at)
             VALUES (?, ?, ?, ?::jsonb, ?, now() + make_interval(days => ?)) RETURNING id, expires_at",
            [$email, hash('sha256', $token), $isAdmin ? 'true' : 'false', json_encode((object) $siteRoles),
                $actor->id, self::INVITE_DAYS]
        )[0];
        return ['id' => (int) $row['id'], 'token' => $token, 'email' => $email, 'expires_at' => (string) $row['expires_at']];
    }

    /** @return list<array{id: int, email: string, is_admin: bool, sites: array<string, string>, expires_at: string}> open invites */
    public function pending(): array {
        $domains = array_column($this->db->queryForList('SELECT id, domain FROM sites'), 'domain', 'id');
        return array_map(static function (array $r) use ($domains) {
            $sites = [];
            foreach (json_decode((string) $r['site_roles'], true) ?: [] as $siteId => $role) {
                if (isset($domains[$siteId])) {
                    $sites[(string) $domains[$siteId]] = (string) $role;
                }
            }
            return [
                'id' => (int) $r['id'], 'email' => (string) $r['email'],
                'is_admin' => $r['is_admin'] === true || $r['is_admin'] === 't',
                'sites' => (object) $sites, 'expires_at' => (string) $r['expires_at'],
            ];
        }, $this->db->queryForList(
            'SELECT id, email, is_admin, site_roles, expires_at FROM invites
             WHERE accepted_at IS NULL AND expires_at > now() ORDER BY created_at DESC'));
    }

    public function revoke(int $id): bool {
        return $this->db->update('DELETE FROM invites WHERE id = ? AND accepted_at IS NULL', [$id]) > 0;
    }

    /** @return array{email: string}|null the open invite behind a token */
    public function find(string $token): ?array {
        $row = $this->open($token);
        return $row === null ? null : ['email' => (string) $row['email']];
    }

    /**
     * Creates the invited user; the invite is used up even if two requests
     * race. One transaction: if creating the user fails, the claim is undone.
     */
    #[Transactional]
    public function accept(string $token, string $name, string $password): User {
        $row = $this->open($token) ?? throw new InvalidInput('this invite link is invalid, used or expired');
        UserService::checkPassword($password);
        // Claim the invite first: of two concurrent accepts only one gets the row.
        $claimed = $this->db->update('UPDATE invites SET accepted_at = now() WHERE id = ? AND accepted_at IS NULL',
            [(int) $row['id']]);
        if ($claimed === 0) {
            throw new InvalidInput('this invite link is invalid, used or expired');
        }
        $roles = [];
        foreach (json_decode((string) $row['site_roles'], true) ?: [] as $siteId => $role) {
            $roles[(int) $siteId] = (string) $role;
        }
        return $this->users->createInvited((string) $row['email'], $name, $password,
            $row['is_admin'] === true || $row['is_admin'] === 't', $roles);
    }

    private function open(string $token): ?array {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
            return null;
        }
        return $this->db->queryForList(
            'SELECT id, email, is_admin, site_roles FROM invites
             WHERE token_hash = ? AND accepted_at IS NULL AND expires_at > now()',
            [hash('sha256', $token)]
        )[0] ?? null;
    }
}
