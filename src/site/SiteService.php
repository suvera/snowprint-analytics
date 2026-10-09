<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class SiteService {

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

    /** @return array{id: int, domain: string, timezone: string} */
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
        return ['id' => $id, 'domain' => $domain, 'timezone' => $timezone];
    }

    /** @return list<array{id: int, domain: string, timezone: string, retention_days: int}> */
    public function all(): array {
        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'domain' => (string) $r['domain'],
            'timezone' => (string) $r['timezone'],
            'retention_days' => (int) $r['retention_days'],
        ], $this->db->queryForList('SELECT id, domain, timezone, retention_days FROM sites ORDER BY domain'));
    }

    /** @return array{id: int, domain: string, timezone: string}|null */
    public function findByDomain(string $domain): ?array {
        try {
            $domain = self::normalizeDomain($domain);
        } catch (InvalidInput) {
            return null;
        }
        $row = $this->db->queryForList('SELECT id, domain, timezone FROM sites WHERE domain = ?', [$domain])[0] ?? null;
        return $row === null ? null
            : ['id' => (int) $row['id'], 'domain' => (string) $row['domain'], 'timezone' => (string) $row['timezone']];
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
