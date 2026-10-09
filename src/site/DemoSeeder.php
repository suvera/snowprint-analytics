<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\suvera\snowprint\ingest\EventBuffer;
use dev\suvera\snowprint\ingest\ReferrerSource;
use dev\suvera\snowprint\query\RollupBuilder;
use dev\suvera\snowprint\query\RollupService;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

/**
 * Demo data (SP-040): fills an empty site with realistic, entirely synthetic
 * traffic so the dashboard and the MCP demo (PRD §7.5) have something to show.
 * It tells one story: DEV (dev.to) sends a steady share of visitors until
 * last Wednesday, when that referral stops, so "compare last week with the
 * week before and explain Wednesday's drop" has an answer. Deterministic for
 * a given seed and day. Refuses sites that already have data.
 */
#[Service]
class DemoSeeder {

    public const MAX_DAYS = 90;

    private const PAGES = [
        ['/', 'Snowprint Demo', 30], ['/pricing', 'Pricing', 12], ['/docs', 'Docs', 14],
        ['/docs/getting-started', 'Getting started', 10], ['/docs/mcp', 'Ask Claude (MCP)', 6],
        ['/blog/privacy-first-analytics', 'Privacy-first analytics', 9],
        ['/blog/one-container', 'One container is enough', 5], ['/about', 'About', 4], ['/signup', 'Sign up', 6],
    ];
    private const SOURCES = [   // referrer host (null = direct), weight
        [null, 34], ['www.google.com', 30], ['duckduckgo.com', 5], ['news.ycombinator.com', 4],
        ['www.reddit.com', 4], ['github.com', 5], ['www.bing.com', 3], ['chatgpt.com', 2], ['www.linkedin.com', 2],
    ];
    private const DEV_SHARE = 0.35;   // share of visitors from dev.to before the drop
    private const COUNTRIES = [   // country, region, city, weight
        ['US', 'California', 'San Francisco', 12], ['US', 'New York', 'New York', 10], ['US', 'Texas', 'Austin', 5],
        ['DE', 'Berlin', 'Berlin', 9], ['DE', 'Bavaria', 'Munich', 4], ['GB', 'England', 'London', 9],
        ['IN', 'Karnataka', 'Bengaluru', 8], ['FR', 'Île-de-France', 'Paris', 6], ['CA', 'Ontario', 'Toronto', 5],
        ['NL', 'North Holland', 'Amsterdam', 4], ['BR', 'São Paulo', 'São Paulo', 4], ['JP', 'Tokyo', 'Tokyo', 3],
        ['AU', 'New South Wales', 'Sydney', 3], ['PL', 'Masovia', 'Warsaw', 3], ['SE', 'Stockholm', 'Stockholm', 2],
    ];
    private const CLIENTS = [   // browser, version, os, os version, device, screen, weight
        ['Chrome', '141.0', 'Windows', '10', 'desktop', [1920, 1080], 26], ['Chrome', '141.0', 'Mac', '10.15', 'desktop', [1512, 982], 14],
        ['Safari', '26.0', 'Mac', '26.0', 'desktop', [1728, 1117], 8], ['Safari', '26.0', 'iOS', '26.0', 'smartphone', [393, 852], 16],
        ['Chrome Mobile', '141.0', 'Android', '16', 'smartphone', [412, 915], 14], ['Firefox', '144.0', 'Windows', '11', 'desktop', [2560, 1440], 6],
        ['Firefox', '144.0', 'GNU/Linux', '', 'desktop', [1920, 1080], 4], ['Microsoft Edge', '141.0', 'Windows', '11', 'desktop', [1920, 1080], 7],
        ['Safari', '26.0', 'iPadOS', '26.0', 'tablet', [1024, 1366], 3], ['Samsung Browser', '28.0', 'Android', '15', 'smartphone', [384, 832], 2],
    ];
    /** Visitors per hour of the local day (relative). */
    private const HOURS = [2, 1, 1, 1, 1, 2, 3, 5, 7, 8, 9, 9, 8, 8, 9, 9, 8, 7, 6, 6, 5, 4, 3, 3];

    #[Autowired]
    private PdbcTemplate $db;

    #[Autowired]
    private SiteService $sites;

    #[Autowired]
    private RollupService $rollups;

    #[Autowired]
    private RollupBuilder $rollupBuilder;

    #[Autowired]
    private GoalService $goals;

    /** @return array{events: int, days: int, goals: int} */
    public function seed(int $siteId, int $days = 35, int $visitorsPerDay = 300, int $seed = 7669, ?\DateTimeImmutable $now = null): array {
        $site = $this->sites->find($siteId) ?? throw new InvalidInput('unknown site');
        if ($this->sites->hasData($siteId)) {
            throw new InvalidInput($site['domain'] . ' already has data; demo data only goes into an empty site');
        }
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidInput('days must be between 1 and ' . self::MAX_DAYS);
        }
        $tz = new \DateTimeZone($site['timezone']);
        $now ??= new \DateTimeImmutable('now');
        $today = $now->setTimezone($tz)->setTime(0, 0);
        $first = $today->modify('-' . $days . ' days');
        $drop = $today->modify('wednesday this week');
        if ($drop > $today->modify('-1 day')) {
            $drop = $drop->modify('-7 days');   // the most recent Wednesday before today
        }
        $this->ensurePartitions($first, $now);
        mt_srand($seed);

        $rows = [];
        $total = 0;
        for ($day = $first; $day <= $today; $day = $day->modify('+1 day')) {
            $weekday = (int) $day->format('N');
            $trend = 1 + 0.004 * (int) $first->diff($day)->days;
            $visitors = (int) round($visitorsPerDay * ($weekday >= 6 ? 0.8 : 1.0) * $trend * (0.94 + 0.12 * mt_rand() / mt_getrandmax()));
            $devShare = $day < $drop ? self::DEV_SHARE : 0.0;
            $visitors = (int) round($visitors * ($day < $drop ? 1.0 : 1 - self::DEV_SHARE));
            for ($v = 0; $v < $visitors; $v++) {
                $start = $day->setTime(self::pick(array_map(null, range(0, 23), self::HOURS)), mt_rand(0, 59), mt_rand(0, 59));
                if ($start > $now->modify('-2 minutes')) {
                    continue;
                }
                foreach ($this->visit($siteId, $site['domain'], $start, $devShare) as $row) {
                    if ($row['ts'] <= $now) {
                        $rows[] = $row;
                    }
                }
                if (count($rows) >= EventBuffer::MAX_BATCH) {
                    $total += $this->insert($rows);
                    $rows = [];
                }
            }
        }
        $total += $this->insert($rows);

        $goals = 0;
        foreach ([['event', 'signup', 'Signed up'], ['pageview', '/pricing', 'Saw pricing']] as [$kind, $match, $name]) {
            try {
                $this->goals->create($siteId, $kind, $match, $name);
                $goals++;
            } catch (InvalidInput) {
                // already there
            }
        }
        // Fresh rows have no planner statistics yet; without them each rollup query
        // of a bulk load picks a bad plan (30 s instead of 1 s for 35 days).
        $this->db->update('ANALYZE events', []);
        $this->rollups->rebuildFromRawEvents($siteId, $site['timezone']);
        $this->rollupBuilder->rollSite($siteId, $site['timezone'], $now, self::MAX_DAYS + 1);
        return ['events' => $total, 'days' => $days + 1, 'goals' => $goals];
    }

    /** @return list<array<string, mixed>> the events of one visit, session ids still unset */
    private function visit(int $siteId, string $domain, \DateTimeImmutable $start, float $devShare): array {
        $hash = bin2hex(random_bytes(16));
        [$browser, $browserVersion, $os, $osVersion, $device, [$w, $h]] = self::pick(self::CLIENTS);
        [$country, $region, $city] = self::pick(self::COUNTRIES);
        $utm = [null, null, null];
        if (mt_rand() / mt_getrandmax() < $devShare) {
            $referrer = 'dev.to';
            $path = '/blog/one-container';
        } elseif (mt_rand(1, 100) <= 5) {
            $referrer = null;
            $utm = ['newsletter', 'email', 'october'];
            $path = '/blog/privacy-first-analytics';
        } else {
            $referrer = self::pick(self::SOURCES);
            $path = $referrer === null || mt_rand(1, 3) === 1 ? '/' : self::pick(self::PAGES)[0];
        }
        $pages = mt_rand(1, 100) <= 46 ? 1 : 1 + min(6, (int) floor(-log(max(1e-9, mt_rand() / mt_getrandmax())) * 1.6));
        $common = [
            'site_id' => $siteId, 'visitor_hash' => $hash, 'hostname' => $domain,
            'referrer_host' => $referrer, 'referrer_source' => ReferrerSource::name($referrer, $utm[0]),
            'utm_source' => $utm[0], 'utm_medium' => $utm[1], 'utm_campaign' => $utm[2], 'utm_term' => null, 'utm_content' => null,
            'country' => $country, 'region' => $region, 'city' => $city, 'browser' => $browser,
            'browser_version' => $browserVersion, 'os' => $os, 'os_version' => $osVersion, 'device' => $device,
            'screen_w' => $w, 'screen_h' => $h, 'props' => null,
        ];
        $events = [];
        $ts = $start;
        for ($i = 0; $i < $pages; $i++) {
            if ($i > 0) {
                // In-site navigation: no referrer and no UTM tags, as the tracker sends it.
                $ts = $ts->modify('+' . mt_rand(8, 240) . ' seconds');
                $path = self::pick(self::PAGES)[0];
                $common = ['referrer_host' => null, 'referrer_source' => null, 'utm_source' => null,
                    'utm_medium' => null, 'utm_campaign' => null] + $common;
            }
            $events[] = $common + ['ts' => $ts, 'name' => 'pageview', 'path' => $path, 'title' => self::title($path)];
            if ($path === '/signup' && mt_rand(1, 100) <= 35) {
                $ts = $ts->modify('+' . mt_rand(20, 120) . ' seconds');
                $plan = mt_rand(1, 4) === 1 ? 'pro' : 'free';
                $events[] = $common + ['ts' => $ts, 'name' => 'signup', 'path' => '/signup', 'title' => 'Sign up',
                    'props' => json_encode(['plan' => $plan])];
                break;
            }
        }
        if (mt_rand(1, 100) <= 4) {
            $ts = $ts->modify('+' . mt_rand(5, 60) . ' seconds');
            $events[] = $common + ['ts' => $ts, 'name' => 'newsletter', 'path' => $path, 'title' => self::title($path)];
        }
        return $events;
    }

    /** Inserts with ids from the events sequence; a visit's session id is its first event's id. */
    private function insert(array $rows): int {
        if ($rows === []) {
            return 0;
        }
        $ids = array_map('intval', array_column($this->db->queryForList(
            "SELECT nextval(pg_get_serial_sequence('events', 'id')) AS id FROM generate_series(1, ?)", [count($rows)]), 'id'));
        $columns = ['id' => '?', 'session_id' => '?'] + EventBuffer::COLUMNS;
        $binds = [];
        $sessions = [];
        foreach ($rows as $i => $row) {
            $row['id'] = $ids[$i];
            $row['session_id'] = $sessions[$row['visitor_hash']] ??= $ids[$i];
            $row['ts'] = $row['ts']->format('Y-m-d H:i:sP');
            foreach (array_keys($columns) as $column) {
                $binds[] = $row[$column] ?? null;
            }
        }
        $tuple = '(' . implode(', ', $columns) . ')';
        $this->db->update('INSERT INTO events (' . implode(', ', array_keys($columns)) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $tuple)), $binds);
        return count($rows);
    }

    /** Monthly partitions for the seeded range (migrations only create them from this month on). */
    private function ensurePartitions(\DateTimeImmutable $from, \DateTimeImmutable $to): void {
        $utc = new \DateTimeZone('UTC');
        $month = $from->setTimezone($utc)->modify('first day of this month')->setTime(0, 0);
        for (; $month <= $to; $month = $month->modify('+1 month')) {
            $name = 'events_' . $month->format('Y_m');
            if ((int) $this->db->queryForScalar('SELECT (to_regclass(?) IS NOT NULL)::int', [$name]) === 1) {
                continue;
            }
            $next = $month->modify('+1 month');
            if ((int) $this->db->queryForScalar('SELECT count(*) FROM events_default WHERE ts >= ? AND ts < ?',
                    [$month->format('c'), $next->format('c')]) > 0) {
                throw new InvalidInput("events for {$month->format('Y-m')} are in events_default; cannot add demo data there");
            }
            $this->db->update(sprintf("CREATE TABLE %s PARTITION OF events FOR VALUES FROM ('%s') TO ('%s')",
                $name, $month->format('Y-m-d'), $next->format('Y-m-d')), []);
        }
    }

    private static function title(string $path): string {
        foreach (self::PAGES as [$p, $title]) {
            if ($p === $path) {
                return $title;
            }
        }
        return 'Snowprint Demo';
    }

    /** Weighted random choice; the weight is each entry's last element. */
    private static function pick(array $entries): mixed {
        $sum = array_sum(array_map(static fn($e) => end($e), $entries));
        $r = mt_rand(1, $sum);
        foreach ($entries as $entry) {
            $r -= end($entry);
            if ($r <= 0) {
                return count($entry) === 2 ? $entry[0] : array_slice($entry, 0, -1);
            }
        }
        return $entries[0][0];
    }
}
