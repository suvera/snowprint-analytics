<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\integration;

use dev\suvera\snowprint\query\Dimensions;
use dev\suvera\snowprint\query\Filters;
use dev\suvera\snowprint\query\Period;
use dev\suvera\snowprint\query\RetentionService;
use dev\suvera\snowprint\query\RollupBuilder;
use dev\suvera\snowprint\query\RollupService;
use dev\suvera\snowprint\query\StatsQuery;
use dev\suvera\snowprint\tests\support\Beans;
use dev\suvera\snowprint\tests\support\PdoPdbcTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Reports read from rollups must equal the same reports over raw events.
 * Ten days of generated traffic in Europe/Berlin, no session crosses local
 * midnight (rollups split those). Runs via tests/integration.sh.
 */
final class RollupTest extends TestCase {

    private const TZ = 'Europe/Berlin';
    private static ?PdoPdbcTemplate $db = null;
    private static int $siteId = 0;
    private StatsQuery $stats;
    private RollupBuilder $builder;
    private RetentionService $retention;

    public static function setUpBeforeClass(): void {
        self::$db = PdoPdbcTemplate::fromEnv();
        if (self::$db === null) {
            return;
        }
        $pdo = self::$db->pdo;
        $pdo->exec("DELETE FROM sites WHERE domain = 'rollup.test'");
        self::$siteId = $site = (int) $pdo->query(
            "INSERT INTO sites (domain, timezone) VALUES ('rollup.test', 'Europe/Berlin') RETURNING id")->fetchColumn();
        $pdo->exec("DELETE FROM events WHERE site_id = $site");
        $pdo->exec("INSERT INTO events (site_id, visitor_hash, session_id, ts, name, path, hostname, referrer_source,
                referrer_host, utm_source, utm_medium, utm_campaign, country, region, city, browser, os, device)
            SELECT $site, decode(md5('r' || d || '-' || v), 'hex'), 100000 + d * 100 + v,
                   (timestamp '2026-09-01 08:00' + d * interval '1 day' + v * interval '7 minutes'
                       + k * interval '2 minutes') AT TIME ZONE 'UTC',
                   CASE WHEN k = 4 THEN 'signup' ELSE 'pageview' END,
                   (ARRAY['/', '/pricing', '/blog/a', '/docs'])[1 + (v + k) % 4], 'rollup.test',
                   CASE WHEN k = 1 THEN (ARRAY[NULL, 'Google', 'hn'])[1 + v % 3] END,
                   CASE WHEN k = 1 THEN (ARRAY[NULL, 'google.com', 'news.ycombinator.com'])[1 + v % 3] END,
                   CASE WHEN k = 1 AND v % 5 = 0 THEN 'newsletter' END,
                   CASE WHEN k = 1 AND v % 5 = 0 THEN 'email' END,
                   CASE WHEN k = 1 AND v % 5 = 0 THEN 'launch' END,
                   (ARRAY['DE', 'US', 'IN'])[1 + (v + d) % 3], 'R' || v % 2, 'C' || v % 3,
                   (ARRAY['Chrome', 'Firefox', 'Safari'])[1 + v % 3], (ARRAY['Linux', 'macOS'])[1 + d % 2],
                   (ARRAY['desktop', 'mobile'])[1 + v % 2]
            FROM generate_series(0, 9) d, generate_series(1, 5 + d) v,
                 generate_series(1, 1 + v % 3 + CASE WHEN v % 4 = 0 THEN 1 ELSE 0 END) k");
    }

    protected function setUp(): void {
        if (self::$db === null) {
            self::markTestSkipped('set SNOWPRINT_TEST_DB_URL (tests/integration.sh does)');
        }
        $rollups = Beans::inject(new RollupService(), 'db', self::$db);
        $this->stats = Beans::inject(Beans::inject(new StatsQuery(), 'db', self::$db), 'rollups', $rollups);
        $this->builder = Beans::inject(Beans::inject(new RollupBuilder(), 'db', self::$db), 'stats', $this->stats);
        $this->retention = Beans::inject(Beans::inject(new RetentionService(), 'db', self::$db), 'rollups', $rollups);
    }

    public function testRollupsMatchRawReports(): void {
        $raw = $this->reports();
        self::assertGreaterThan(0, $raw['2026-09-01..2026-09-10']['overview']['visitors']);

        // Partly rolled up: four days from rollups, the rest raw.
        self::assertSame(4, $this->builder->rollSite(self::$siteId, self::TZ, new \DateTimeImmutable('2026-09-20'), 4));
        $rolledDays = (int) self::$db->pdo->query("SELECT count(DISTINCT day) FROM rollup_daily WHERE site_id = " . self::$siteId)->fetchColumn();
        self::assertSame(4, $rolledDays);
        self::assertEquals($raw, $this->reports(), 'four days rolled up');

        $this->builder->rollSite(self::$siteId, self::TZ, new \DateTimeImmutable('2026-09-20'));
        self::assertEquals($raw, $this->reports(), 'every day rolled up');

        // The rollups now answer even after the raw events are gone.
        self::$db->pdo->exec('UPDATE sites SET retention_days = 3 WHERE id = ' . self::$siteId);
        $result = $this->retention->apply(new \DateTimeImmutable('2026-09-12 12:00', new \DateTimeZone(self::TZ)));
        self::assertGreaterThan(0, $result['events_deleted']);
        $left = self::$db->pdo->query("SELECT min(ts) FROM events WHERE site_id = " . self::$siteId)->fetchColumn();
        self::assertSame('2026-09-09', (new \DateTimeImmutable($left))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d'));
        $after = $this->reports();
        self::assertEquals($raw['2026-09-01..2026-09-10']['overview'], $after['2026-09-01..2026-09-10']['overview']);
        self::assertEquals($raw['2026-09-01..2026-09-10']['page'], $after['2026-09-01..2026-09-10']['page']);
    }

    public function testRetentionWaitsForRollups(): void {
        $other = (int) self::$db->pdo->query(
            "INSERT INTO sites (domain, retention_days) VALUES ('unrolled.test', 1) RETURNING id")->fetchColumn();
        try {
            self::assertNull($this->retention->cutoff($other, 'UTC', 1, new \DateTimeImmutable('2026-09-12')));
            self::assertNull($this->retention->cutoff($other, 'UTC', 0, new \DateTimeImmutable('2026-09-12')));
        } finally {
            self::$db->pdo->exec("DELETE FROM sites WHERE id = $other");
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function reports(): array {
        $reports = [];
        foreach (['2026-09-01..2026-09-10', '2026-09-03..2026-09-05', '2026-09-01..2026-12-31'] as $spec) {
            $period = Period::parse($spec, self::TZ);
            $none = Filters::none();
            $report = [
                'overview' => $this->stats->overview(self::$siteId, $period, $none),
                'daily' => $this->stats->timeseries(self::$siteId, $period, $none, 'visits', 'day'),
                'monthly' => $this->stats->timeseries(self::$siteId, $period, $none, 'pageviews', 'month'),
            ];
            foreach (Dimensions::breakdownNames() as $dimension) {
                $report[$dimension] = $this->stats->breakdown(self::$siteId, $period, $none, $dimension, StatsQuery::MAX_LIMIT);
            }
            $reports[$spec] = $report;
        }
        return $reports;
    }
}
