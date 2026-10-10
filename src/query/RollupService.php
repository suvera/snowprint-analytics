<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\query;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\stereotype\Transactional;

/**
 * Read side of the daily rollups (rollup_daily, migration 007). Each site has
 * a watermark in job_watermarks ("rollup:<site id>"): the instant of the
 * site-local midnight up to which every day is rolled up. RollupBuilder
 * advances it.
 */
#[Service]
class RollupService {

    #[Autowired]
    private PdbcTemplate $db;

    public static function watermarkJob(int $siteId): string {
        return 'rollup:' . $siteId;
    }

    public function watermark(int $siteId): ?\DateTimeImmutable {
        $row = $this->db->queryForList('SELECT position FROM job_watermarks WHERE job = ?',
            [self::watermarkJob($siteId)])[0] ?? null;
        return $row === null ? null : new \DateTimeImmutable((string) $row['position']);
    }

    /**
     * The whole local days of the period that are rolled up, as instants of
     * local midnights in the period's timezone; null when there are none.
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    public function rolledRange(int $siteId, Period $period): ?array {
        $watermark = $this->watermark($siteId);
        if ($watermark === null) {
            return null;
        }
        $tz = $period->timezone;
        $from = $period->start->setTimezone($tz);
        if ($from->format('H:i:s') !== '00:00:00') {
            $from = $from->setTime(0, 0)->modify('+1 day');
        }
        $to = min($period->end, $watermark)->setTimezone($tz)->setTime(0, 0);
        return $from < $to ? [$from, $to] : null;
    }

    /**
     * Summed rollup rows per value of $dimension ('' = totals) over the range.
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable} $range
     * @return array<string, array<string, int>>
     */
    public function sums(int $siteId, array $range, string $dimension): array {
        $rows = $this->db->queryForList(
            'SELECT value, sum(visitors) AS visitors, sum(visits) AS visits, sum(pageviews) AS pageviews,
                    sum(events) AS events, sum(bounces) AS bounces, sum(duration_sum) AS duration_sum
             FROM rollup_daily WHERE site_id = ? AND dimension = ? AND day >= ? AND day < ?
             GROUP BY value',
            [$siteId, $dimension, $range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')]
        );
        $result = [];
        foreach ($rows as $r) {
            $sums = [];
            foreach (StatsQuery::SUMS as $k) {
                $sums[$k] = (int) $r[$k];
            }
            $result[(string) $r['value']] = $sums;
        }
        return $result;
    }

    /**
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable} $range
     * @return array<string, int> local date => metric
     */
    public function dailyTotals(int $siteId, array $range, string $metric): array {
        $column = in_array($metric, StatsQuery::METRICS, true) ? $metric : 'visitors';
        $rows = $this->db->queryForList(
            "SELECT to_char(day, 'YYYY-MM-DD') AS day, $column AS value FROM rollup_daily
             WHERE site_id = ? AND dimension = '' AND day >= ? AND day < ?",
            [$siteId, $range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')]
        );
        return array_map('intval', array_column($rows, 'value', 'day'));
    }

    /**
     * Forgets a site's rollups for the days whose raw events still exist, so
     * they are rebuilt (after a timezone change). Older rollups stay.
     */
    #[Transactional]
    public function rebuildFromRawEvents(int $siteId, string $timezone): void {
        $first = $this->db->queryForList('SELECT min(ts) AS first FROM events WHERE site_id = ?', [$siteId])[0]['first'] ?? null;
        if ($first !== null) {
            $day = (new \DateTimeImmutable((string) $first))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
            $this->db->update('DELETE FROM rollup_daily WHERE site_id = ? AND day >= ?', [$siteId, $day]);
        }
        $this->db->update('DELETE FROM job_watermarks WHERE job = ?', [self::watermarkJob($siteId)]);
    }
}
