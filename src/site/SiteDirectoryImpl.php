<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;

/**
 * Keeps the whole domain → id map in worker memory and reloads it every
 * REFRESH_SECONDS, so ingest never queries `sites` per event. New sites start
 * receiving events within that interval.
 */
#[Component]
class SiteDirectoryImpl implements SiteDirectory {

    public const REFRESH_SECONDS = 30;

    #[Autowired]
    private PdbcTemplate $db;

    /** @var array<string, int> */
    private array $sites = [];
    private int $loadedAt = 0;

    public function findSiteId(string $domain): ?int {
        if (time() - $this->loadedAt >= self::REFRESH_SECONDS) {
            $this->reload();
        }
        return $this->sites[self::normalize($domain)] ?? null;
    }

    public static function normalize(string $domain): string {
        $domain = strtolower(trim($domain));
        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    private function reload(): void {
        $sites = [];
        foreach ($this->db->queryForList('SELECT id, domain FROM sites') as $row) {
            $sites[self::normalize((string) $row['domain'])] = (int) $row['id'];
        }
        $this->sites = $sites;
        $this->loadedAt = time();
    }
}
