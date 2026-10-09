<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\query;

use dev\suvera\snowprint\site\InvalidInput;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

/**
 * Reporting queries shared by the dashboard and MCP (PRD §6.2, §7.3). Exact
 * counts over raw events (decision D4). A filter selects the sessions that
 * contain a matching event; session metrics (visits, bounce rate, duration)
 * are computed over those whole sessions.
 *
 * Visitors are counted by the daily-rotating hash, so a person who returns on
 * another day counts again: multi-day "visitors" are daily-unique visitors.
 */
#[Service]
class StatsQuery {

    public const METRICS = ['visitors', 'pageviews', 'visits', 'events'];
    public const INTERVALS = ['hour', 'day', 'month'];
    public const MAX_LIMIT = 100;
    public const MIN_ANOMALY_DIFF = 3;

    #[Autowired]
    private PdbcTemplate $db;

    /** @return array<string, int|float> */
    public function overview(int $siteId, Period $period, Filters $filters): array {
        [$cte, $binds] = $this->baseCte($siteId, $period, $filters);
        $row = $this->db->queryForList($cte . "
            SELECT (SELECT count(DISTINCT visitor_hash) FROM matched) AS visitors,
                   (SELECT count(*) FROM matched WHERE name = 'pageview') AS pageviews,
                   (SELECT count(*) FROM matched WHERE name <> 'pageview') AS events,
                   count(*) AS visits,
                   coalesce(round(100.0 * count(*) FILTER (WHERE n = 1) / nullif(count(*), 0)), 0) AS bounce_rate,
                   coalesce(round(avg(dur)), 0) AS visit_duration,
                   coalesce(round(avg(pv)::numeric, 2), 0) AS views_per_visit
            FROM sessions", $binds)[0];
        return [
            'visitors' => (int) $row['visitors'],
            'visits' => (int) $row['visits'],
            'pageviews' => (int) $row['pageviews'],
            'events' => (int) $row['events'],
            'views_per_visit' => (float) $row['views_per_visit'],
            'bounce_rate' => (int) $row['bounce_rate'],
            'visit_duration' => (int) $row['visit_duration'],
        ];
    }

    /**
     * Overview plus the same metrics for the previous period and % changes.
     * @return array{current: array, previous: array, change: array<string, ?float>}
     */
    public function overviewWithComparison(int $siteId, Period $period, Filters $filters): array {
        $current = $this->overview($siteId, $period, $filters);
        $previous = $this->overview($siteId, $period->previous(), $filters);
        $change = [];
        foreach ($current as $metric => $value) {
            $before = $previous[$metric];
            $change[$metric] = $before == 0 ? null : round(100 * ($value - $before) / $before, 1);
        }
        return ['current' => $current, 'previous' => $previous, 'change' => $change];
    }

    /** @return list<array{date: string, value: int}> one row per bucket, gaps filled with 0 */
    public function timeseries(int $siteId, Period $period, Filters $filters, string $metric, ?string $interval = null): array {
        if (!in_array($metric, self::METRICS, true)) {
            throw new InvalidInput('unknown metric "' . $metric . '"; use ' . implode(', ', self::METRICS));
        }
        $interval ??= $period->defaultInterval();
        if (!in_array($interval, self::INTERVALS, true)) {
            throw new InvalidInput('unknown interval "' . $interval . '"; use hour, day or month');
        }
        $value = match ($metric) {
            'visitors' => 'count(DISTINCT visitor_hash)',
            'pageviews' => "count(*) FILTER (WHERE name = 'pageview')",
            'visits' => 'count(DISTINCT sid)',
            'events' => "count(*) FILTER (WHERE name <> 'pageview')",
        };
        [$cte, $binds] = $this->baseCte($siteId, $period, $filters);
        $rows = $this->db->queryForList($cte . "
            SELECT to_char(date_trunc('$interval', ts AT TIME ZONE ?), 'YYYY-MM-DD\"T\"HH24:MI') AS bucket, $value AS value
            FROM matched GROUP BY 1", [...$binds, $period->timezone->getName()]);
        $values = array_column($rows, 'value', 'bucket');

        $series = [];
        $step = ['hour' => '+1 hour', 'day' => '+1 day', 'month' => '+1 month'][$interval];
        $cursor = $period->start->setTimezone($period->timezone);
        $cursor = match ($interval) {
            'hour' => $cursor->setTime((int) $cursor->format('H'), 0),
            'day' => $cursor->setTime(0, 0),
            'month' => $cursor->modify('first day of this month')->setTime(0, 0),
        };
        $end = $period->end->setTimezone($period->timezone);
        for (; $cursor < $end; $cursor = $cursor->modify($step)) {
            $key = $cursor->format('Y-m-d\TH:i');
            $series[] = [
                'date' => $interval === 'hour' ? $cursor->format('Y-m-d H:00') : $cursor->format($interval === 'day' ? 'Y-m-d' : 'Y-m'),
                'value' => (int) ($values[$key] ?? 0),
            ];
        }
        return $series;
    }

    /** @return list<array{value: string, visitors: int, visits: int, pageviews: int, events: int, bounce_rate: int, visit_duration: int}> */
    public function breakdown(int $siteId, Period $period, Filters $filters, string $dimension, int $limit = 10): array {
        if (!in_array($dimension, Dimensions::breakdownNames(), true)) {
            throw new InvalidInput('unknown dimension "' . $dimension . '"; use one of '
                . implode(', ', Dimensions::breakdownNames()));
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        [$cte, $binds] = $this->baseCte($siteId, $period, $filters);

        if (in_array($dimension, Dimensions::SESSION_DIMENSIONS, true)) {
            // Entry/exit page: the first/last pageview of each session.
            $order = $dimension === 'entry_page' ? 'ASC' : 'DESC';
            $rows = $this->db->queryForList($cte . ",
                edge AS (
                    SELECT DISTINCT ON (sid) sid, visitor_hash, path FROM base
                    WHERE name = 'pageview' AND sid IN (SELECT sid FROM sessions)
                    ORDER BY sid, ts $order, id $order
                )
                SELECT e.path AS value, count(DISTINCT e.visitor_hash) AS visitors, count(*) AS visits,
                       count(*) AS pageviews, 0 AS events,
                       round(100.0 * count(*) FILTER (WHERE s.n = 1) / count(*)) AS bounce_rate,
                       round(avg(s.dur)) AS visit_duration
                FROM edge e JOIN sessions s USING (sid)
                GROUP BY e.path ORDER BY visits DESC, e.path LIMIT ?", [...$binds, $limit]);
        } else {
            // Counts over matching events; bounce rate and duration over the
            // sessions in which the value appears.
            $column = Dimensions::COLUMNS[$dimension];
            $none = $dimension === 'source' ? Dimensions::DIRECT : Dimensions::NONE;
            $onlyEvents = $dimension === 'event' ? "WHERE name <> 'pageview'" : '';
            $rows = $this->db->queryForList($cte . ",
                tagged AS (SELECT coalesce($column, ?) AS value, * FROM matched $onlyEvents),
                counts AS (
                    SELECT value, count(DISTINCT visitor_hash) AS visitors, count(DISTINCT sid) AS visits,
                           count(*) FILTER (WHERE name = 'pageview') AS pageviews,
                           count(*) FILTER (WHERE name <> 'pageview') AS events
                    FROM tagged GROUP BY value
                ),
                quality AS (
                    SELECT t.value,
                           round(100.0 * count(*) FILTER (WHERE s.n = 1) / count(*)) AS bounce_rate,
                           round(avg(s.dur)) AS visit_duration
                    FROM (SELECT DISTINCT value, sid FROM tagged) t JOIN sessions s USING (sid)
                    GROUP BY t.value
                )
                SELECT c.*, q.bounce_rate, q.visit_duration
                FROM counts c JOIN quality q USING (value)
                ORDER BY c.visitors DESC, c.value LIMIT ?", [...$binds, $none, $limit]);
        }
        return array_map(static fn(array $r) => [
            'value' => (string) $r['value'],
            'visitors' => (int) $r['visitors'],
            'visits' => (int) $r['visits'],
            'pageviews' => (int) $r['pageviews'],
            'events' => (int) $r['events'],
            'bounce_rate' => (int) $r['bounce_rate'],
            'visit_duration' => (int) $r['visit_duration'],
        ], $rows);
    }

    /**
     * Conversions per goal: unique visitors who completed it, total completions,
     * and conversion rate against all visitors in the period (filters apply).
     * @return list<array{id: int, name: string, kind: string, match: string, visitors: int, completions: int, conversion_rate: float}>
     */
    public function goals(int $siteId, Period $period, Filters $filters): array {
        [$cte, $binds] = $this->baseCte($siteId, $period, $filters);
        $rows = $this->db->queryForList($cte . ",
            goal AS (
                SELECT id, name, kind, match,
                       replace(replace(replace(replace(match, '\\', '\\\\'), '%', '\\%'), '_', '\\_'), '*', '%') AS pattern
                FROM goals WHERE site_id = ?
            )
            SELECT g.id, g.name, g.kind, g.match,
                   count(DISTINCT m.visitor_hash) AS visitors, count(m.id) AS completions,
                   (SELECT count(DISTINCT visitor_hash) FROM matched) AS total
            FROM goal g
            LEFT JOIN matched m ON (g.kind = 'event' AND m.name = g.match)
                                OR (g.kind = 'pageview' AND m.name = 'pageview' AND m.path LIKE g.pattern)
            GROUP BY g.id, g.name, g.kind, g.match ORDER BY visitors DESC, g.id", [...$binds, $siteId]);
        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'kind' => (string) $r['kind'],
            'match' => (string) $r['match'], 'visitors' => (int) $r['visitors'],
            'completions' => (int) $r['completions'],
            'conversion_rate' => (int) $r['total'] === 0 ? 0.0 : round(100 * (int) $r['visitors'] / (int) $r['total'], 1),
        ], $rows);
    }

    /** @return array{visitors: int, pages: list<array{page: string, visitors: int}>} last 5 minutes */
    public function realtime(int $siteId): array {
        $where = "site_id = ? AND ts >= now() - interval '5 minutes'";
        $visitors = (int) $this->db->queryForScalar("SELECT count(DISTINCT visitor_hash) FROM events WHERE $where", [$siteId]);
        $pages = $this->db->queryForList(
            "SELECT path AS page, count(DISTINCT visitor_hash) AS visitors FROM events
             WHERE $where AND name = 'pageview' GROUP BY path ORDER BY visitors DESC, path LIMIT 10",
            [$siteId]
        );
        return [
            'visitors' => $visitors,
            'pages' => array_map(static fn($r) => ['page' => (string) $r['page'], 'visitors' => (int) $r['visitors']], $pages),
        ];
    }

    /**
     * Days whose visitors deviate more than $sigma standard deviations from
     * the mean of the preceding $baselineDays days (spread floored at Poisson
     * noise, and at least MIN_ANOMALY_DIFF visitors away from the mean).
     * @return list<array{date: string, visitors: int, baseline_mean: float, baseline_stddev: float, z_score: float, direction: string}>
     */
    public function anomalies(int $siteId, Period $period, Filters $filters, float $sigma = 2.0, int $baselineDays = 28): array {
        $tz = $period->timezone->getName();
        $from = $period->start->setTimezone($period->timezone)->modify("-$baselineDays days")->format('Y-m-d');
        $to = $period->end->setTimezone($period->timezone)->modify('-1 second')->format('Y-m-d');
        $daily = $this->timeseries($siteId, Period::parse("$from..$to", $tz), $filters, 'visitors', 'day');

        $anomalies = [];
        $periodStart = $period->start->setTimezone($period->timezone)->format('Y-m-d');
        foreach ($daily as $i => $day) {
            if ($day['date'] < $periodStart || $i < 7) {
                continue;
            }
            $window = array_column(array_slice($daily, max(0, $i - $baselineDays), min($i, $baselineDays)), 'value');
            $mean = array_sum($window) / count($window);
            $std = sqrt(array_sum(array_map(static fn($v) => ($v - $mean) ** 2, $window)) / count($window));
            // Visitor counts are noisy counts: never trust a spread below Poisson noise
            // (sqrt of the mean), and ignore differences of a couple of visitors.
            $spread = max($std, sqrt(max($mean, 1.0)));
            $z = ($day['value'] - $mean) / $spread;
            if (abs($z) >= $sigma && abs($day['value'] - $mean) >= self::MIN_ANOMALY_DIFF) {
                $anomalies[] = [
                    'date' => $day['date'], 'visitors' => $day['value'],
                    'baseline_mean' => round($mean, 1), 'baseline_stddev' => round($std, 1),
                    'z_score' => round($z, 2), 'direction' => $z > 0 ? 'spike' : 'drop',
                ];
            }
        }
        return $anomalies;
    }

    /**
     * CTEs shared by every report: base (period events with session id),
     * matched (events passing the filters) and sessions (whole sessions that
     * contain a matched event).
     * @return array{0: string, 1: list<mixed>}
     */
    private function baseCte(int $siteId, Period $period, Filters $filters): array {
        [$condition, $filterBinds] = $filters->toSql();
        // Acquisition (source, referrer, UTM) is a session attribute: every event
        // inherits its session's entry values. Otherwise each in-site navigation
        // (same-site referrer, stored as no source) would count as "Direct".
        $acquisition = '';
        foreach (['referrer_source', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $c) {
            $acquisition .= "first_value($c) OVER entry AS $c, ";
        }
        $sql = "WITH raw AS (
                    SELECT id, visitor_hash, coalesce(session_id, id) AS sid, ts, name,
                           path, hostname, referrer_source, referrer_host, utm_source, utm_medium, utm_campaign,
                           utm_term, utm_content, country, region, city, browser, os, device
                    FROM events WHERE site_id = ? AND ts >= ? AND ts < ?
                ),
                base AS (
                    SELECT id, visitor_hash, sid, ts, name, path, hostname, $acquisition
                           country, region, city, browser, os, device
                    FROM raw
                    WINDOW entry AS (PARTITION BY sid ORDER BY ts, id ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING)
                ),
                matched AS (SELECT * FROM base WHERE $condition),
                sessions AS (
                    SELECT sid, count(*) FILTER (WHERE name = 'pageview') AS pv, count(*) AS n,
                           extract(epoch FROM max(ts) - min(ts)) AS dur
                    FROM base WHERE sid IN (SELECT sid FROM matched) GROUP BY sid
                )";
        return [$sql, [$siteId, $period->start->format('c'), $period->end->format('c'), ...$filterBinds]];
    }
}
