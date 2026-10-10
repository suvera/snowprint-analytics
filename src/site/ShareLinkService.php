<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\suvera\snowprint\infra\RateLimiter;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

/**
 * Public share links (SP-046, decision D19): a secret link that opens one
 * site's dashboard read-only, without an account, optionally behind a
 * password. Only the SHA-256 digest of the token is stored, so the link is
 * shown once; a lost link is deleted and created again.
 */
#[Service]
class ShareLinkService {

    /** Wrong passwords per link and worker before unlocking pauses. */
    public const MAX_FAILURES = 10;
    public const FAILURE_WINDOW_SECONDS = 900;

    #[Autowired]
    private PdbcTemplate $db;

    #[Autowired]
    private RateLimiter $limiter;

    /** The dashboard path of a share token (append it to the public URL). */
    public static function path(string $token): string {
        return '/ui/#/share/' . $token;
    }

    /**
     * @return array{id: int, label: string, has_password: bool, created_at: string, last_used_at: null, token: string, path: string}
     */
    public function create(int $siteId, ?int $createdBy, string $label, ?string $password): array {
        $label = mb_substr(trim($label), 0, 255);
        $hash = null;
        if ($password !== null && $password !== '') {
            UserService::checkPassword($password);
            $hash = password_hash($password, PASSWORD_ARGON2ID);
        }
        $token = bin2hex(random_bytes(16));
        $row = $this->db->queryForList(
            'INSERT INTO share_links (site_id, token_hash, label, password_hash, created_by)
             VALUES (?, ?, ?, ?, ?) RETURNING id, created_at',
            [$siteId, hash('sha256', $token), $label, $hash, $createdBy]
        )[0];
        return [
            'id' => (int) $row['id'], 'label' => $label, 'has_password' => $hash !== null,
            'created_at' => (string) $row['created_at'], 'last_used_at' => null,
            'token' => $token, 'path' => self::path($token),
        ];
    }

    /** @return list<array{id: int, label: string, has_password: bool, created_at: string, last_used_at: ?string}> */
    public function forSite(int $siteId): array {
        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'label' => (string) $r['label'],
            'has_password' => $r['password_hash'] !== null && $r['password_hash'] !== '',
            'created_at' => (string) $r['created_at'],
            'last_used_at' => $r['last_used_at'] === null ? null : (string) $r['last_used_at'],
        ], $this->db->queryForList(
            'SELECT id, label, password_hash, created_at, last_used_at FROM share_links WHERE site_id = ? ORDER BY id',
            [$siteId]));
    }

    public function delete(int $siteId, int $id): bool {
        return $this->db->update('DELETE FROM share_links WHERE id = ? AND site_id = ?', [$id, $siteId]) > 0;
    }

    /** @return array{id: int, site_id: int, label: string, password_hash: ?string}|null the link behind a token */
    public function resolve(string $token): ?array {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        $row = $this->db->queryForList(
            'SELECT id, site_id, label, password_hash FROM share_links WHERE token_hash = ?',
            [hash('sha256', $token)]
        )[0] ?? null;
        if ($row === null) {
            return null;
        }
        $passwordHash = $row['password_hash'] === null || $row['password_hash'] === '' ? null : (string) $row['password_hash'];
        return ['id' => (int) $row['id'], 'site_id' => (int) $row['site_id'], 'label' => (string) $row['label'],
            'password_hash' => $passwordHash];
    }

    /** Records that the link was opened (once per page load, not per report). */
    public function touch(int $id): void {
        $this->db->update('UPDATE share_links SET last_used_at = now() WHERE id = ?', [$id]);
    }

    /**
     * Checks a link's password. After MAX_FAILURES wrong passwords within
     * FAILURE_WINDOW_SECONDS every attempt is refused until the window ends
     * (counted in PostgreSQL, so across all workers and pods).
     */
    public function checkPassword(array $link, string $password, ?int $now = null): bool {
        $bucket = 'share:' . $link['id'];
        if ($this->limiter->hits($bucket, self::FAILURE_WINDOW_SECONDS, $now) >= self::MAX_FAILURES) {
            throw new InvalidInput('too many wrong passwords; try again later');
        }
        if ($link['password_hash'] === null || password_verify($password, $link['password_hash'])) {
            $this->limiter->clear($bucket);
            return true;
        }
        $this->limiter->hit($bucket, self::FAILURE_WINDOW_SECONDS, $now);
        return false;
    }
}
