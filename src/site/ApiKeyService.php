<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\suvera\snowprint\infra\CacheConfig;
use dev\winterframework\cache\stereotype\CacheEvict;
use dev\winterframework\cache\stereotype\Cacheable;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\stereotype\Transactional;

/**
 * API keys for MCP and the server-side API (PRD §6.3). The full key is shown
 * once at creation; only its SHA-256 digest is stored.
 */
#[Service]
class ApiKeyService {

    public const KEY_PREFIX = 'sp_';
    public const DISPLAY_PREFIX_LENGTH = 10;

    #[Autowired]
    private PdbcTemplate $db;

    public static function digest(string $key): string {
        return hash('sha256', $key);
    }

    /**
     * @param list<int>|null $siteIds null = all sites
     * @return array{id: int, key: string}
     */
    #[Transactional]
    public function create(string $name, ?array $siteIds, bool $canWrite): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidInput('name is required (max 255 characters)');
        }
        if ($siteIds === []) {
            throw new InvalidInput('give at least one site, or all sites');
        }
        $key = self::KEY_PREFIX . bin2hex(random_bytes(24));
        $id = (int) $this->db->queryForScalar(
            'INSERT INTO api_keys (name, key_prefix, key_hash, all_sites, can_write) VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$name, substr($key, 0, self::DISPLAY_PREFIX_LENGTH), self::digest($key),
                $siteIds === null ? 'true' : 'false', $canWrite ? 'true' : 'false']
        );
        foreach ($siteIds ?? [] as $siteId) {
            $this->db->update('INSERT INTO api_key_sites (api_key_id, site_id) VALUES (?, ?)', [$id, $siteId]);
        }
        return ['id' => $id, 'key' => $key];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array {
        $rows = $this->db->queryForList(
            "SELECT k.id, k.name, k.key_prefix, k.all_sites, k.can_write, k.created_at, k.last_used_at, k.revoked_at,
                    coalesce(string_agg(s.domain, ',' ORDER BY s.domain), '') AS domains
             FROM api_keys k
             LEFT JOIN api_key_sites ks ON ks.api_key_id = k.id
             LEFT JOIN sites s ON s.id = ks.site_id
             GROUP BY k.id ORDER BY k.id"
        );
        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'prefix' => (string) $r['key_prefix'],
            'sites' => self::bool($r['all_sites']) ? 'all' : array_values(array_filter(explode(',', (string) $r['domains']))),
            'write' => self::bool($r['can_write']),
            'created_at' => $r['created_at'],
            'last_used_at' => $r['last_used_at'],
            'revoked' => $r['revoked_at'] !== null,
        ], $rows);
    }

    #[CacheEvict(cacheNames: CacheConfig::API_KEYS, allEntries: true)]
    public function revoke(int $id): bool {
        return $this->db->update('UPDATE api_keys SET revoked_at = now() WHERE id = ? AND revoked_at IS NULL', [$id]) > 0;
    }

    /** Resolves a presented key; cached per worker for 30 s (revocation takes effect within that). */
    public function authenticate(string $key): ?ApiKey {
        if (!str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }
        return $this->findByDigest(self::digest($key));
    }

    /**
     * The active key with this digest. Cached by digest, so the raw key is
     * never a cache key; public because #[Cacheable] only advises public methods.
     */
    #[Cacheable(cacheNames: CacheConfig::API_KEYS)]
    public function findByDigest(string $digest): ?ApiKey {
        $row = $this->db->queryForList(
            'SELECT id, name, all_sites, can_write FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL',
            [$digest]
        )[0] ?? null;
        $apiKey = null;
        if ($row !== null) {
            $siteIds = self::bool($row['all_sites']) ? null : array_map('intval', array_column(
                $this->db->queryForList('SELECT site_id FROM api_key_sites WHERE api_key_id = ?', [$row['id']]),
                'site_id'
            ));
            $apiKey = new ApiKey((int) $row['id'], (string) $row['name'], $siteIds, self::bool($row['can_write']));
            $this->db->update('UPDATE api_keys SET last_used_at = now() WHERE id = ?', [$row['id']]);
        }
        return $apiKey;
    }

    private static function bool(mixed $value): bool {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }
}
