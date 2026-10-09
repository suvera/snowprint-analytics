<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class SiteService {

    public const MAX_RETENTION_DAYS = 3650;

    #[Autowired]
    private PdbcTemplate $db;

    /** Accepts "example.com", "https://www.example.com/path" etc.; returns the bare domain. */
    public static function normalizeDomain(string $input): string {
        $value = strtolower(trim($input));
        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }
        $value = rtrim(explode('/', $value)[0], '.');
        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $value)) {
            throw new InvalidInput('not a valid domain: ' . $input);
        }
        return $value;
    }

    /**
     * Legacy IANA names that browsers still report (ICU's canonical IDs, e.g.
     * Chrome's Intl API returns "Asia/Calcutta"), mapped to their current names.
     * Not every PHP build knows the old names (Ubuntu moved them to
     * tzdata-legacy), so they are stored under the current name.
     */
    public const TIMEZONE_ALIASES = [
        'Asia/Calcutta' => 'Asia/Kolkata',
        'Asia/Katmandu' => 'Asia/Kathmandu',
        'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
        'Asia/Rangoon' => 'Asia/Yangon',
        'Asia/Dacca' => 'Asia/Dhaka',
        'Asia/Thimbu' => 'Asia/Thimphu',
        'Asia/Ulan_Bator' => 'Asia/Ulaanbaatar',
        'Europe/Kiev' => 'Europe/Kyiv',
        'Europe/Uzhgorod' => 'Europe/Kyiv',
        'Europe/Zaporozhye' => 'Europe/Kyiv',
        'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
        'America/Catamarca' => 'America/Argentina/Catamarca',
        'America/Cordoba' => 'America/Argentina/Cordoba',
        'America/Jujuy' => 'America/Argentina/Jujuy',
        'America/Mendoza' => 'America/Argentina/Mendoza',
        'America/Indianapolis' => 'America/Indiana/Indianapolis',
        'America/Louisville' => 'America/Kentucky/Louisville',
        'America/Godthab' => 'America/Nuuk',
        'America/Coral_Harbour' => 'America/Atikokan',
        'Atlantic/Faeroe' => 'Atlantic/Faroe',
        'Pacific/Enderbury' => 'Pacific/Kanton',
        'Pacific/Truk' => 'Pacific/Chuuk',
        'Pacific/Ponape' => 'Pacific/Pohnpei',
    ];

    /** Validates an IANA timezone name and returns its current name. */
    public static function timezone(string $input): string {
        $timezone = trim($input);
        $timezone = self::TIMEZONE_ALIASES[$timezone] ?? $timezone;
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidInput('unknown timezone: ' . $input);
        }
        return $timezone;
    }

    /** @return array{id: int, domain: string, timezone: string, retention_days: int} */
    public function create(string $domain, string $timezone = 'UTC'): array {
        $domain = self::normalizeDomain($domain);
        $timezone = self::timezone($timezone);
        $exists = $this->db->queryForScalar('SELECT count(*) FROM sites WHERE domain = ?', [$domain]);
        if ((int) $exists > 0) {
            throw new InvalidInput('site already exists: ' . $domain);
        }
        $id = (int) $this->db->queryForScalar(
            'INSERT INTO sites (domain, timezone) VALUES (?, ?) RETURNING id',
            [$domain, $timezone]
        );
        return ['id' => $id, 'domain' => $domain, 'timezone' => $timezone,
            'retention_days' => (int) $this->db->queryForScalar('SELECT retention_days FROM sites WHERE id = ?', [$id])];
    }

    /** @return list<array{id: int, domain: string, timezone: string, retention_days: int}> */
    public function all(): array {
        return array_map(self::row(...),
            $this->db->queryForList('SELECT id, domain, timezone, retention_days FROM sites ORDER BY domain'));
    }

    /** @return array{id: int, domain: string, timezone: string, retention_days: int}|null */
    public function find(int $siteId): ?array {
        $row = $this->db->queryForList('SELECT id, domain, timezone, retention_days FROM sites WHERE id = ?', [$siteId])[0] ?? null;
        return $row === null ? null : self::row($row);
    }

    /** @return array{id: int, domain: string, timezone: string, retention_days: int}|null */
    public function findByDomain(string $domain): ?array {
        try {
            $domain = self::normalizeDomain($domain);
        } catch (InvalidInput) {
            return null;
        }
        $row = $this->db->queryForList('SELECT id, domain, timezone, retention_days FROM sites WHERE domain = ?', [$domain])[0] ?? null;
        return $row === null ? null : self::row($row);
    }

    /**
     * Changes a site's reporting timezone and/or raw-event retention (days,
     * 0 = forever). The domain is fixed: tracking snippets refer to it.
     * @return array{id: int, domain: string, timezone: string, retention_days: int}
     */
    public function update(int $siteId, ?string $timezone, ?int $retentionDays): array {
        if ($timezone !== null) {
            $this->db->update('UPDATE sites SET timezone = ? WHERE id = ?', [self::timezone($timezone), $siteId]);
        }
        if ($retentionDays !== null) {
            $this->db->update('UPDATE sites SET retention_days = ? WHERE id = ?', [self::retentionDays($retentionDays), $siteId]);
        }
        return $this->find($siteId) ?? throw new InvalidInput('unknown site');
    }

    /**
     * Deletes a site with all its data: raw events, rollups, goals, user and
     * API key grants, share links. Events that ingest still accepts in the next seconds
     * (it caches the site list) are removed by the retention job.
     */
    public function delete(int $siteId): void {
        $this->db->update('INSERT INTO deleted_sites (site_id) VALUES (?) ON CONFLICT (site_id) DO NOTHING', [$siteId]);
        $this->db->update('DELETE FROM events WHERE site_id = ?', [$siteId]);
        $this->db->update('DELETE FROM job_watermarks WHERE job = ?', ['rollup:' . $siteId]);
        $this->db->update('DELETE FROM sites WHERE id = ?', [$siteId]);
    }

    /** True once the site has received any event (raw or rolled up). */
    public function hasData(int $siteId): bool {
        return (bool) $this->db->queryForScalar(
            'SELECT EXISTS (SELECT 1 FROM events WHERE site_id = ?) OR EXISTS (SELECT 1 FROM rollup_daily WHERE site_id = ?)',
            [$siteId, $siteId]);
    }

    public static function retentionDays(int $days): int {
        if ($days < 0 || $days > self::MAX_RETENTION_DAYS) {
            throw new InvalidInput('retention must be 0 (keep forever) to ' . self::MAX_RETENTION_DAYS . ' days');
        }
        return $days;
    }

    /** @return array{id: int, domain: string, timezone: string, retention_days: int} */
    private static function row(array $row): array {
        return [
            'id' => (int) $row['id'],
            'domain' => (string) $row['domain'],
            'timezone' => (string) $row['timezone'],
            'retention_days' => (int) $row['retention_days'],
        ];
    }

    /** @param list<string> $domains @return list<int> site ids, in input order */
    public function idsForDomains(array $domains): array {
        $ids = [];
        foreach ($domains as $domain) {
            $normalized = self::normalizeDomain($domain);
            $id = $this->db->queryForList('SELECT id FROM sites WHERE domain = ?', [$normalized])[0]['id'] ?? null;
            if ($id === null) {
                throw new InvalidInput('unknown site: ' . $normalized);
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }
}
