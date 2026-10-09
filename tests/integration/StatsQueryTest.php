<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\integration;

use dev\suvera\snowprint\query\Filters;
use dev\suvera\snowprint\query\Period;
use dev\suvera\snowprint\query\RollupService;
use dev\suvera\snowprint\query\StatsQuery;
use dev\suvera\snowprint\tests\support\Beans;
use dev\suvera\snowprint\tests\support\PdoPdbcTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Hand-checked numbers on a small fixed data set. Runs via tests/integration.sh.
 *
 *   A: 2026-10-01 10:00 "/" (Google, Chrome, desktop, DE)
 *      2026-10-01 10:02 "/pricing"                        -> 1 visit, 2 pageviews, 120 s
 *   B: 2026-10-01 11:00 "/" (direct, Firefox, mobile, US)  -> 1 visit, bounce
 *   C: 2026-10-02 09:00 "/blog/x" (utm_source=hn)
 *      2026-10-02 09:01 event "signup"                      -> 1 visit, 60 s
 */
final class StatsQueryTest extends TestCase {

    private static ?PdoPdbcTemplate $db = null;
    private static int $siteId = 0;
    private StatsQuery $stats;
    private Period $week;

    public static function setUpBeforeClass(): void {
        self::$db = PdoPdbcTemplate::fromEnv();
        if (self::$db === null) {
            return;
        }
        $pdo = self::$db->pdo;
        $pdo->exec("DELETE FROM events; DELETE FROM rollup_daily; DELETE FROM job_watermarks; DELETE FROM sites WHERE domain = 'stats.test'");
        self::$siteId = (int) $pdo->query("INSERT INTO sites (domain) VALUES ('stats.test') RETURNING id")->fetchColumn();
        $rows = [
            // hash, session, ts, name, path, source, utm_source, browser, device, country
            ['aa', 1, '2026-10-01 10:00:00+00', 'pageview', '/', 'Google', null, 'Chrome', 'desktop', 'DE'],
            ['aa', 1, '2026-10-01 10:02:00+00', 'pageview', '/pricing', null, null, 'Chrome', 'desktop', 'DE'],
            ['bb', 2, '2026-10-01 11:00:00+00', 'pageview', '/', null, null, 'Firefox', 'mobile', 'US'],
            ['cc', 3, '2026-10-02 09:00:00+00', 'pageview', '/blog/x', 'hn', 'hn', 'Safari', 'desktop', 'GB'],
            ['cc', 3, '2026-10-02 09:01:00+00', 'signup', '/blog/x', 'hn', 'hn', 'Safari', 'desktop', 'GB'],
        ];
        $stmt = $pdo->prepare("INSERT INTO events (site_id, visitor_hash, session_id, ts, name, path, referrer_source,
            utm_source, browser, device, country) VALUES (?, decode(?, 'hex'), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($rows as $r) {
            $stmt->execute([self::$siteId, ...$r]);
        }
    }

    protected function setUp(): void {
        if (self::$db === null) {
            self::markTestSkipped('set SNOWPRINT_TEST_DB_URL (tests/integration.sh does)');
        }
        $this->stats = Beans::inject(Beans::inject(new StatsQuery(), 'db', self::$db), 'rollups',
            Beans::inject(new RollupService(), 'db', self::$db));
        $this->week = Period::parse('2026-10-01..2026-10-07', 'UTC');
    }

    public function testOverview(): void {
        self::assertSame([
            'visitors' => 3, 'visits' => 3, 'pageviews' => 4, 'events' => 1,
            'views_per_visit' => 1.33, 'bounce_rate' => 33, 'visit_duration' => 60,
        ], $this->stats->overview(self::$siteId, $this->week, Filters::none()));
    }

    public function testPageFilterKeepsWholeSessions(): void {
        $o = $this->stats->overview(self::$siteId, $this->week, Filters::of(['page' => '/pricing']));
        self::assertSame(1, $o['visitors']);
        self::assertSame(1, $o['pageviews'], 'only matching pageviews are counted');
        self::assertSame(0, $o['bounce_rate'], 'the session had two pageviews');
        self::assertSame(120, $o['visit_duration']);
    }

    public function testComparisonWithEmptyPreviousPeriod(): void {
        $c = $this->stats->overviewWithComparison(self::$siteId, $this->week, Filters::none());
        self::assertSame(0, $c['previous']['visitors']);
        self::assertNull($c['change']['visitors']);
    }

    public function testDailyTimeseriesFillsGaps(): void {
        $series = $this->stats->timeseries(self::$siteId, $this->week, Filters::none(), 'visitors');
        self::assertCount(7, $series);
        self::assertSame(['date' => '2026-10-01', 'value' => 2], $series[0]);
        self::assertSame(['date' => '2026-10-02', 'value' => 1], $series[1]);
        self::assertSame(0, $series[6]['value']);
    }

    public function testHourlyTimeseriesInSiteTimezone(): void {
        $day = Period::parse('2026-10-01', 'Europe/Berlin');
        $series = $this->stats->timeseries(self::$siteId, $day, Filters::none(), 'pageviews', 'hour');
        self::assertCount(24, $series);
        $byHour = array_column($series, 'value', 'date');
        self::assertSame(2, $byHour['2026-10-01 12:00'], '10:00 UTC is 12:00 in Berlin');
        self::assertSame(1, $byHour['2026-10-01 13:00']);
    }

    public function testSourceBreakdown(): void {
        $rows = $this->stats->breakdown(self::$siteId, $this->week, Filters::none(), 'source');
        self::assertSame(['Direct / None', 'Google', 'hn'], array_column($rows, 'value'));
        $byValue = array_column($rows, null, 'value');
        // Sources belong to sessions: A came from Google (its /pricing page is not "Direct"),
        // B is the only direct session.
        self::assertSame(['visitors' => 1, 'visits' => 1, 'pageviews' => 2, 'bounce_rate' => 0, 'visit_duration' => 120],
            array_intersect_key($byValue['Google'], array_flip(['visitors', 'visits', 'pageviews', 'bounce_rate', 'visit_duration'])));
        self::assertSame(['visitors' => 1, 'bounce_rate' => 100],
            array_intersect_key($byValue['Direct / None'], array_flip(['visitors', 'bounce_rate'])));
    }

    public function testSourceFilterKeepsTheWholeSession(): void {
        $o = $this->stats->overview(self::$siteId, $this->week, Filters::of(['source' => 'Google']));
        self::assertSame(2, $o['pageviews'], 'both pages of the Google session');
        $direct = $this->stats->overview(self::$siteId, $this->week, Filters::of(['source' => 'Direct / None']));
        self::assertSame(1, $direct['visits'], 'only session B, not A\'s second page');
    }

    public function testEntryAndExitPages(): void {
        $entry = $this->stats->breakdown(self::$siteId, $this->week, Filters::none(), 'entry_page');
        // Sessions entering at "/": A (2 pageviews, 120 s) and B (bounce, 0 s).
        self::assertSame(['value' => '/', 'visitors' => 2, 'visits' => 2, 'pageviews' => 2, 'events' => 0,
            'bounce_rate' => 50, 'visit_duration' => 60], $entry[0]);
        $exit = array_column($this->stats->breakdown(self::$siteId, $this->week, Filters::none(), 'exit_page'), 'visits', 'value');
        self::assertSame(['/' => 1, '/blog/x' => 1, '/pricing' => 1], $exit);
    }

    public function testEventBreakdownExcludesPageviews(): void {
        $rows = $this->stats->breakdown(self::$siteId, $this->week, Filters::none(), 'event');
        self::assertSame([['value' => 'signup', 'visitors' => 1, 'visits' => 1, 'pageviews' => 0, 'events' => 1,
            'bounce_rate' => 0, 'visit_duration' => 60]], $rows);
    }

    public function testFilteredBreakdown(): void {
        $rows = $this->stats->breakdown(self::$siteId, $this->week, Filters::of(['device' => 'desktop']), 'country');
        self::assertSame(['DE', 'GB'], array_column($rows, 'value'));
    }

    public function testGoalConversions(): void {
        $pdo = self::$db->pdo;
        $pdo->exec("INSERT INTO goals (site_id, name, kind, match) VALUES
            (" . self::$siteId . ", 'Signup', 'event', 'signup'),
            (" . self::$siteId . ", 'Pricing', 'pageview', '/pri*'),
            (" . self::$siteId . ", 'Never', 'pageview', '/100%_off')");
        $goals = $this->stats->goals(self::$siteId, $this->week, Filters::none());
        $pdo->exec('DELETE FROM goals');
        $byName = array_column($goals, null, 'name');
        self::assertSame(1, $byName['Signup']['visitors']);
        self::assertSame(33.3, $byName['Signup']['conversion_rate']);
        self::assertSame(1, $byName['Pricing']['completions']);
        self::assertSame(0, $byName['Never']['visitors'], 'LIKE metacharacters in goals are literal');
    }

    public function testAnomalies(): void {
        $pdo = self::$db->pdo;
        // 5 visitors a day for 35 days, then 40 on the last day.
        $pdo->exec("INSERT INTO events (site_id, visitor_hash, session_id, ts, name, path)
            SELECT " . self::$siteId . ", decode(md5(d::text || v::text), 'hex'), 1000 + v, d + interval '12 hours', 'pageview', '/a'
            FROM generate_series('2026-08-01'::date, '2026-09-04'::date, '1 day') d, generate_series(1, 5) v");
        $pdo->exec("INSERT INTO events (site_id, visitor_hash, session_id, ts, name, path)
            SELECT " . self::$siteId . ", decode(md5('spike' || v::text), 'hex'), 2000 + v, '2026-09-05 12:00+00', 'pageview', '/a'
            FROM generate_series(1, 40) v");
        $found = $this->stats->anomalies(self::$siteId, Period::parse('2026-08-25..2026-09-05', 'UTC'), Filters::none());
        self::assertCount(1, $found);
        self::assertSame('2026-09-05', $found[0]['date']);
        self::assertSame('spike', $found[0]['direction']);
        $pdo->exec("DELETE FROM events WHERE path = '/a'");
    }
}
