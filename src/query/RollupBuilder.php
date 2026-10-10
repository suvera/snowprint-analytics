<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\query;

use dev\suvera\snowprint\infra\LockConfig;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\concurrent\Lockable;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\stereotype\Transactional;

/**
 * Writes daily rollups (decision D13): for each site, every complete
 * site-local day after the watermark becomes one rollup_daily row per
 * dimension value, computed by StatsQuery::rawSums so rollups and raw reports
 * agree. A day is complete LAG after its local midnight, once the sessionizer
 * has caught up. Re-rolling a day replaces its rows, so concurrent workers
 * converge on the same result.
 */
#[Service]
class RollupBuilder {

    public const LAG = '+1 hour';
    public const MAX_DAYS_PER_RUN = 60;
    /** Values kept per dimension and day; the rest is summed into OTHER. */
    public const MAX_VALUES = 1000;
    public const OTHER = '(other)';

    #[Autowired]
    private PdbcTemplate $db;

    #[Autowired]
    private StatsQuery $stats;

    /**
     * One run at a time across all pods (the scheduled job and the operator
     * console); a busy lock throws LockException at once.
     * @return int days rolled up, over all sites
     */
    #[Lockable(name: 'snowprint-rollup', ttlSeconds: 1800, lockManager: LockConfig::PG)]
    public function rollPending(?\DateTimeImmutable $now = null, int $maxDays = self::MAX_DAYS_PER_RUN): int {
        $rolled = 0;
        foreach ($this->db->queryForList('SELECT id, timezone FROM sites ORDER BY id') as $site) {
            $rolled += $this->rollSite((int) $site['id'], (string) $site['timezone'], $now, $maxDays);
        }
        return $rolled;
    }

    public function rollSite(int $siteId, string $timezone, ?\DateTimeImmutable $now = null, int $maxDays = self::MAX_DAYS_PER_RUN): int {
        $tz = new \DateTimeZone($timezone);
        $now ??= new \DateTimeImmutable('now');
        $job = RollupService::watermarkJob($siteId);
        $row = $this->db->queryForList('SELECT position FROM job_watermarks WHERE job = ?', [$job])[0] ?? null;
        if ($row !== null) {
            $day = (new \DateTimeImmutable((string) $row['position']))->setTimezone($tz);
        } else {
            $first = $this->db->queryForList('SELECT min(ts) AS first FROM events WHERE site_id = ?', [$siteId])[0]['first'] ?? null;
            $day = ($first === null ? $now : new \DateTimeImmutable((string) $first))->setTimezone($tz)->setTime(0, 0);
            $this->saveWatermark($job, $day);
        }

        $rolled = 0;
        for (; $rolled < $maxDays; $rolled++) {
            $next = $day->modify('+1 day');
            if ($next->modify(self::LAG) > $now) {
                break;
            }
            $this->rollDay($siteId, $day, $next);
            $day = $next;
        }
        return $rolled;
    }

    /**
     * Replaces the rollup rows of the local day [$from, $to) and moves the
     * watermark to $to, in one transaction: reports never see the day half
     * written. Public because #[Transactional] only advises public methods.
     */
    #[Transactional]
    public function rollDay(int $siteId, \DateTimeImmutable $from, \DateTimeImmutable $to): void {
        $date = $from->format('Y-m-d');
        $this->db->update('DELETE FROM rollup_daily WHERE site_id = ? AND day = ?', [$siteId, $date]);
        $none = Filters::none();
        $rows = [];
        foreach ($this->stats->rawSums($siteId, $from, $to, $none, null) as $sums) {
            $rows[] = ['', '', $sums];
        }
        if ($rows === []) {
            $this->saveWatermark(RollupService::watermarkJob($siteId), $to);
            return;
        }
        foreach (Dimensions::breakdownNames() as $dimension) {
            foreach (self::capped($this->stats->rawSums($siteId, $from, $to, $none, $dimension)) as $value => $sums) {
                $rows[] = [$dimension, (string) $value, $sums];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $binds = [];
            foreach ($chunk as [$dimension, $value, $s]) {
                array_push($binds, $siteId, $date, $dimension, $value, $s['visitors'], $s['visits'],
                    $s['pageviews'], $s['events'], $s['bounces'], $s['duration_sum']);
            }
            $this->db->update('INSERT INTO rollup_daily (site_id, day, dimension, value, visitors, visits, pageviews,
                    events, bounces, duration_sum) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'))
                . ' ON CONFLICT (site_id, dimension, day, value) DO UPDATE SET visitors = EXCLUDED.visitors,
                    visits = EXCLUDED.visits, pageviews = EXCLUDED.pageviews, events = EXCLUDED.events,
                    bounces = EXCLUDED.bounces, duration_sum = EXCLUDED.duration_sum', $binds);
        }
        $this->saveWatermark(RollupService::watermarkJob($siteId), $to);
    }

    /**
     * The MAX_VALUES values with most visitors; the rest summed into OTHER.
     * @param array<string, array<string, int>> $rows
     * @return array<string, array<string, int>>
     */
    public static function capped(array $rows, int $max = self::MAX_VALUES): array {
        if (count($rows) <= $max) {
            return $rows;
        }
        uksort($rows, static fn($a, $b) => [$rows[$b]['visitors'], (string) $a] <=> [$rows[$a]['visitors'], (string) $b]);
        $kept = array_slice($rows, 0, $max - 1, true);
        $other = $kept[self::OTHER] ?? array_fill_keys(StatsQuery::SUMS, 0);
        foreach (array_slice($rows, $max - 1, null, true) as $sums) {
            foreach (StatsQuery::SUMS as $k) {
                $other[$k] += $sums[$k];
            }
        }
        $kept[self::OTHER] = $other;
        return $kept;
    }

    private function saveWatermark(string $job, \DateTimeImmutable $position): void {
        $this->db->update('INSERT INTO job_watermarks (job, position, updated_at) VALUES (?, ?, now())
            ON CONFLICT (job) DO UPDATE SET position = EXCLUDED.position, updated_at = now()',
            [$job, $position->format('c')]);
    }
}
