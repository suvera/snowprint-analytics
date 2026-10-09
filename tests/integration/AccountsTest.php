<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\integration;

use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\InviteService;
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

    public static function setUpBeforeClass(): void {
        self::$db = PdoPdbcTemplate::fromEnv();
    }

    protected function setUp(): void {
        if (self::$db === null) {
            self::markTestSkipped('set SNOWPRINT_TEST_DB_URL (tests/integration.sh does)');
        }
        self::$db->pdo->exec("DELETE FROM invites; DELETE FROM users; DELETE FROM sites WHERE domain LIKE '%.accounts.test'");
        $this->users = Beans::inject(new UserService(), 'db', self::$db);
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

    public function testRetentionBounds(): void {
        $id = $this->sites->create('r.accounts.test')['id'];
        self::assertSame(0, $this->sites->update($id, null, 0)['retention_days']);
        $this->expectException(InvalidInput::class);
        $this->sites->update($id, null, 5000);
    }
}
