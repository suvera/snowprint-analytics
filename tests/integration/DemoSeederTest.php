<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\integration;

use dev\suvera\snowprint\query\Filters;
use dev\suvera\snowprint\query\Period;
use dev\suvera\snowprint\query\RollupBuilder;
use dev\suvera\snowprint\query\RollupService;
use dev\suvera\snowprint\query\StatsQuery;
use dev\suvera\snowprint\site\GoalService;
use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\SiteService;
use dev\suvera\snowprint\tests\support\Beans;
use dev\suvera\snowprint\tests\support\PdoPdbcTemplate;
use dev\suvera\snowprint\web\admin\DemoSeeder;
use PHPUnit\Framework\TestCase;

/** Demo data tells the PRD §7.5 story and is rolled up. Runs via tests/integration.sh. */
final class DemoSeederTest extends TestCase {

    private static ?PdoPdbcTemplate $db = null;

    public static function setUpBeforeClass(): void {
        self::$db = PdoPdbcTemplate::fromEnv();
    }

    public function testSeedsAnEmptySiteOnce(): void {
        if (self::$db === null) {
            self::markTestSkipped('set SNOWPRINT_TEST_DB_URL (tests/integration.sh does)');
        }
        $db = self::$db;
        $db->pdo->exec("DELETE FROM sites WHERE domain = 'demo.test'");
        $sites = Beans::inject(new SiteService(), 'db', $db);
        $rollups = Beans::inject(new RollupService(), 'db', $db);
        $stats = Beans::inject(Beans::inject(new StatsQuery(), 'db', $db), 'rollups', $rollups);
        $seeder = new DemoSeeder();
        foreach (['db' => $db, 'sites' => $sites, 'rollups' => $rollups, 'goals' => Beans::inject(new GoalService(), 'db', $db),
                     'rollupBuilder' => Beans::inject(Beans::inject(new RollupBuilder(), 'db', $db), 'stats', $stats)] as $name => $bean) {
            Beans::inject($seeder, $name, $bean);
        }
        $site = $sites->create('demo.test', 'UTC')['id'];
        $now = new \DateTimeImmutable('2026-10-20 12:00:00 UTC');   // a Tuesday: the drop is Wednesday 2026-10-14

        $result = $seeder->seed($site, 35, 300, 1, $now);
        self::assertGreaterThan(10000, $result['events']);
        self::assertSame(36, $result['days']);
        self::assertSame(2, $result['goals']);

        $count = fn(string $sql) => (int) $db->pdo->query(str_replace('$site', (string) $site, $sql))->fetchColumn();
        self::assertSame(0, $count('SELECT count(*) FROM events WHERE site_id = $site AND session_id IS NULL'));
        self::assertSame(0, $count("SELECT count(*) FROM events WHERE site_id = \$site AND ts > '2026-10-20 12:00:00+00'"));
        self::assertGreaterThan(0, $count("SELECT count(*) FROM events WHERE site_id = \$site AND referrer_host = 'dev.to'
            AND ts >= '2026-10-07' AND ts < '2026-10-14'"), 'DEV sends visitors before Wednesday');
        self::assertSame(0, $count("SELECT count(*) FROM events WHERE site_id = \$site AND referrer_host = 'dev.to'
            AND ts >= '2026-10-14'"), 'and none from Wednesday on');
        self::assertSame(0, $count("SELECT count(*) FROM events WHERE site_id = \$site AND referrer_host IS NOT NULL
            AND name = 'pageview' AND id <> session_id"), 'only the first pageview of a visit has a referrer');
        self::assertSame(35, $count("SELECT count(DISTINCT day) FROM rollup_daily WHERE site_id = \$site"),
            'every finished day is rolled up');

        // PRD §7.5: find_anomalies reports Wednesday as a drop.
        $anomalies = $stats->anomalies($site, Period::parse('14d', 'UTC', $now), Filters::none(), 2.0, 28, $now);
        self::assertSame('2026-10-14', $anomalies[0]['date'] ?? null, json_encode($anomalies));
        self::assertSame('drop', $anomalies[0]['direction']);
        self::assertNotContains('2026-10-20', array_column($anomalies, 'date'), 'today, still filling up, is no anomaly');

        $this->expectException(InvalidInput::class);
        $seeder->seed($site, 35, 300, 1, $now);
    }
}
