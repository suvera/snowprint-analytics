<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\integration;

use dev\suvera\snowprint\infra\RateLimiter;
use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\InviteService;
use dev\suvera\snowprint\site\ShareLinkService;
use dev\suvera\snowprint\site\SiteService;
use dev\suvera\snowprint\site\UserService;
use dev\suvera\snowprint\tests\support\Beans;
use dev\suvera\snowprint\tests\support\PdoPdbcTemplate;
use PHPUnit\Framework\TestCase;

/** Invites, roles, the last-admin guard and site deletion. Runs via tests/integration.sh. */
final class AccountsTest extends TestCase {

    private static ?PdoPdbcTemplate $db = null;
    private UserService $users;
    private InviteService $invites;
    private SiteService $sites;
    private RateLimiter $limiter;

    public static function setUpBeforeClass(): void {
        self::$db = PdoPdbcTemplate::fromEnv();
    }

    protected function setUp(): void {
        if (self::$db === null) {
            self::markTestSkipped('set SNOWPRINT_TEST_DB_URL (tests/integration.sh does)');
        }
        self::$db->pdo->exec("DELETE FROM invites; DELETE FROM users; DELETE FROM rate_limits;"
            . " DELETE FROM sites WHERE domain LIKE '%.accounts.test'");
        $this->limiter = Beans::inject(new RateLimiter(), 'db', self::$db);
        $this->users = Beans::inject(Beans::inject(new UserService(), 'db', self::$db), 'limiter', $this->limiter);
        $this->invites = Beans::inject(Beans::inject(new InviteService(), 'db', self::$db), 'users', $this->users);
        $this->sites = Beans::inject(new SiteService(), 'db', self::$db);
    }

    public function testInviteCreatesAViewerOnce(): void {
        $admin = $this->users->createFirstAdmin('admin@accounts.test', 'Admin', 'admin password 1');
        $a = $this->sites->create('a.accounts.test')['id'];
        $b = $this->sites->create('b.accounts.test')['id'];
        $invite = $this->invites->create($admin, 'Viewer@Accounts.test', false, [$a => 'viewer', $b => 'admin']);
        self::assertSame(['email' => 'viewer@accounts.test'], $this->invites->find($invite['token']));
        self::assertCount(1, $this->invites->pending());

        $viewer = $this->invites->accept($invite['token'], 'Vic', 'viewer password 1');
        self::assertFalse($viewer->isAdmin);
        self::assertTrue($viewer->canRead($a));
        self::assertFalse($viewer->canManage($a));
        self::assertTrue($viewer->canManage($b));
        self::assertNull($this->invites->find($invite['token']), 'an invite works once');
        self::assertSame([], $this->invites->pending());
        $this->expectException(InvalidInput::class);
        $this->invites->accept($invite['token'], 'Again', 'viewer password 1');
    }

    public function testInviteForAnExistingEmailIsRefused(): void {
        $admin = $this->users->createFirstAdmin('admin@accounts.test', 'Admin', 'admin password 1');
        $this->expectException(InvalidInput::class);
        $this->invites->create($admin, 'admin@accounts.test', true, []);
    }

    public function testTheLastAdminStays(): void {
        $admin = $this->users->createFirstAdmin('admin@accounts.test', 'Admin', 'admin password 1');
        try {
            $this->users->updateAccess($admin->id, false, []);
            self::fail('the last admin lost admin rights');
        } catch (InvalidInput) {
        }
        $other = $this->invites->accept(
            $this->invites->create($admin, 'second@accounts.test', true, [])['token'], 'Second', 'second password 1');
        self::assertFalse($this->users->updateAccess($admin->id, false, [])->isAdmin, 'fine with another admin');
        try {
            $this->users->delete($other, $other->id);
            self::fail('a user deleted themselves');
        } catch (InvalidInput) {
        }
        $this->expectException(InvalidInput::class);
        $this->users->delete($admin, $other->id);   // $other is now the last admin
    }

    public function testDeletingASiteRemovesItsData(): void {
        $id = $this->sites->create('gone.accounts.test')['id'];
        $pdo = self::$db->pdo;
        $pdo->exec("INSERT INTO events (site_id, visitor_hash, session_id, ts, name, path)
                    VALUES ($id, '\\xaa', 1, now(), 'pageview', '/')");
        $pdo->exec("INSERT INTO rollup_daily VALUES ($id, '2026-09-01', '', '', 1, 1, 1, 0, 1, 0)");
        $this->sites->delete($id);
        self::assertSame(0, (int) $pdo->query("SELECT count(*) FROM events WHERE site_id = $id")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT count(*) FROM rollup_daily WHERE site_id = $id")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT count(*) FROM deleted_sites WHERE site_id = $id")->fetchColumn());
        self::assertNull($this->sites->findByDomain('gone.accounts.test'));
    }

    public function testShareLinks(): void {
        $id = $this->sites->create('shared.accounts.test')['id'];
        $shares = Beans::inject(Beans::inject(new ShareLinkService(), 'db', self::$db), 'limiter', $this->limiter);
        $open = $shares->create($id, null, ' Public ', null);
        $locked = $shares->create($id, null, 'Team', 'share password 1');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $open['token']);
        self::assertSame('/ui/#/share/' . $open['token'], $open['path']);
        self::assertSame('Public', $open['label']);

        $pdo = self::$db->pdo;
        self::assertSame(0, (int) $pdo->query("SELECT count(*) FROM share_links WHERE token_hash = '{$open['token']}'")->fetchColumn(),
            'only the digest is stored');
        self::assertSame(['Public', 'Team'], array_column($shares->forSite($id), 'label'));
        self::assertSame([false, true], array_column($shares->forSite($id), 'has_password'));
        self::assertArrayNotHasKey('token', $shares->forSite($id)[0]);

        self::assertSame($id, $shares->resolve($open['token'])['site_id']);
        self::assertNull($shares->resolve($open['token'] . '0'));
        self::assertNull($shares->resolve(str_repeat('0', 32)));
        $link = $shares->resolve($locked['token']);
        self::assertFalse($shares->checkPassword($link, 'wrong password'));
        self::assertTrue($shares->checkPassword($link, 'share password 1'));
        self::assertTrue($shares->checkPassword($shares->resolve($open['token']), ''), 'no password needed');

        self::assertFalse($shares->delete($id + 1, $open['id']), 'only on its own site');
        self::assertTrue($shares->delete($id, $open['id']));
        self::assertNull($shares->resolve($open['token']));
        $this->sites->delete($id);
        self::assertNull($shares->resolve($locked['token']), 'deleting the site deletes its links');
    }

    public function testShareLinkPasswordAttemptsAreLimited(): void {
        $id = $this->sites->create('guess.accounts.test')['id'];
        $shares = Beans::inject(Beans::inject(new ShareLinkService(), 'db', self::$db), 'limiter', $this->limiter);
        $link = $shares->resolve($shares->create($id, null, '', 'share password 1')['token']);
        for ($i = 0; $i < ShareLinkService::MAX_FAILURES; $i++) {
            self::assertFalse($shares->checkPassword($link, 'guess ' . $i, 1000));
        }
        try {
            $shares->checkPassword($link, 'share password 1', 1000 + ShareLinkService::FAILURE_WINDOW_SECONDS - 1);
            self::fail('the right password was accepted while paused');
        } catch (InvalidInput) {
        }
        self::assertTrue($shares->checkPassword($link, 'share password 1', 1000 + ShareLinkService::FAILURE_WINDOW_SECONDS));
    }

    public function testSignInLocksAfterRepeatedFailuresAcrossWorkers(): void {
        $this->users->createFirstAdmin('lock@accounts.test', 'Admin', 'admin password 1');
        for ($i = 0; $i < UserService::MAX_FAILURES; $i++) {
            self::assertNull($this->users->authenticate('lock@accounts.test', 'guess ' . $i));
        }
        // A second worker (a fresh bean on the same database) sees the same lock.
        $other = Beans::inject(Beans::inject(new UserService(), 'db', self::$db), 'limiter', $this->limiter);
        try {
            $other->authenticate('LOCK@accounts.test', 'admin password 1');
            self::fail('the right password was accepted while locked');
        } catch (InvalidInput) {
        }
        $buckets = self::$db->pdo->query('SELECT bucket FROM rate_limits')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['login:' . hash('sha256', 'lock@accounts.test')], $buckets, 'never the address itself');
    }

    public function testRateLimiterWindows(): void {
        self::assertSame(1, $this->limiter->hit('t', 60, 1000));
        self::assertSame(2, $this->limiter->hit('t', 60, 1059));
        self::assertSame(2, $this->limiter->hits('t', 60, 1059));
        self::assertSame(0, $this->limiter->hits('t', 60, 1060), 'window over');
        self::assertSame(1, $this->limiter->hit('t', 60, 1060), 'a new window starts');
        self::assertSame(0, $this->limiter->purge(1060 + RateLimiter::KEEP_SECONDS));
        self::assertSame(1, $this->limiter->purge(1061 + RateLimiter::KEEP_SECONDS));
        $this->limiter->hit('t', 60);
        $this->limiter->clear('t');
        self::assertSame(0, $this->limiter->hits('t', 60));
    }

    public function testRetentionBounds(): void {
        $id = $this->sites->create('r.accounts.test')['id'];
        self::assertSame(0, $this->sites->update($id, null, 0)['retention_days']);
        $this->expectException(InvalidInput::class);
        $this->sites->update($id, null, 5000);
    }
}
