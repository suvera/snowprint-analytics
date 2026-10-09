<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\query;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\util\log\Wlf4p;

/**
 * Deletes raw events past each site's retention (sites.retention_days, 0 =
 * keep forever; PRD §6.4). Only days that are already rolled up are deleted,
 * so totals survive. Monthly events partitions that are past the retention of
 * every site are dropped whole, which is cheaper than deleting their rows.
 * Events that arrive for a site after it was deleted are removed here too.
 */
#[Service]
class RetentionService {
    use Wlf4p;

    #[Autowired]
    private PdbcTemplate $db;

    #[Autowired]
    private RollupService $rollups;

    /** @return array{events_deleted: int, partitions_dropped: list<string>} */
    public function apply(?\DateTimeImmutable $now = null): array {
        $now ??= new \DateTimeImmutable('now');
        $cutoffs = [];
        $keepsAll = false;
        foreach ($this->db->queryForList('SELECT id, timezone, retention_days FROM sites ORDER BY id') as $site) {
            $cutoff = $this->cutoff((int) $site['id'], (string) $site['timezone'], (int) $site['retention_days'], $now);
            if ($cutoff === null) {
                $keepsAll = true;
            } else {
                $cutoffs[(int) $site['id']] = $cutoff;
            }
        }

        $dropped = $keepsAll || $cutoffs === [] ? [] : $this->dropPartitionsBefore(min($cutoffs));
        // Late events of deleted sites (ingest caches the site list for 30 s).
        $deleted = 0;
        foreach ($this->db->queryForList('SELECT site_id FROM deleted_sites') as $row) {
            $deleted += $this->db->update('DELETE FROM events WHERE site_id = ?', [(int) $row['site_id']]);
        }
        $this->db->update("DELETE FROM deleted_sites WHERE deleted_at < now() - interval '1 day'", []);
        foreach ($cutoffs as $siteId => $cutoff) {
            $deleted += $this->db->update('DELETE FROM events WHERE site_id = ? AND ts < ?', [$siteId, $cutoff->format('c')]);
        }
        if ($deleted > 0 || $dropped !== []) {
            self::logInfo("Retention: deleted $deleted events, dropped partitions [" . implode(', ', $dropped) . ']');
        }
        return ['events_deleted' => $deleted, 'partitions_dropped' => $dropped];
    }

    /**
     * Local midnight $retentionDays before today, but never past the rollup
     * watermark; null when nothing may be deleted.
     */
    public function cutoff(int $siteId, string $timezone, int $retentionDays, \DateTimeImmutable $now): ?\DateTimeImmutable {
        if ($retentionDays <= 0) {
            return null;
        }
        $watermark = $this->rollups->watermark($siteId);
        if ($watermark === null) {
            return null;
        }
        $cutoff = $now->setTimezone(new \DateTimeZone($timezone))->setTime(0, 0)->modify("-$retentionDays days");
        return min($cutoff, $watermark);
    }

    /** @return list<string> names of the dropped events_YYYY_MM partitions that end on or before $cutoff */
    private function dropPartitionsBefore(\DateTimeImmutable $cutoff): array {
        $names = array_column($this->db->queryForList(
            "SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = 'events'::regclass AND c.relname ~ '^events_[0-9]{4}_[0-9]{2}$' ORDER BY 1"
        ), 'relname');
        $dropped = [];
        foreach ($names as $name) {
            $start = \DateTimeImmutable::createFromFormat('!Y_m', substr((string) $name, 7), new \DateTimeZone('UTC'));
            if ($start !== false && $start->modify('+1 month') <= $cutoff) {
                $this->db->update('DROP TABLE IF EXISTS ' . $name, []);
                $dropped[] = (string) $name;
            }
        }
        return $dropped;
    }
}
