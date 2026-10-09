<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\privacy;

use dev\suvera\snowprint\privacy\SaltService;
use dev\suvera\snowprint\privacy\SaltServiceImpl;
use dev\suvera\snowprint\privacy\SaltStore;
use dev\suvera\snowprint\tests\support\Beans;
use PHPUnit\Framework\TestCase;

final class SaltServiceImplTest extends TestCase {

    public function testSaltIsCreatedOncePerDayAndCached(): void {
        $store = new InMemorySaltStore();
        $svc = $this->service($store, '2026-10-09');

        $first = $svc->currentSalt();
        self::assertSame(SaltService::SALT_BYTES, strlen($first));
        self::assertSame($first, $svc->currentSalt());
        self::assertSame(1, $store->calls, 'store hit once per day');
    }

    public function testNewDayGetsNewSalt(): void {
        $store = new InMemorySaltStore();
        $svc = $this->service($store, '2026-10-09');
        $day1 = $svc->currentSalt();

        $svc->day = '2026-10-10';
        self::assertNotSame($day1, $svc->currentSalt());
    }

    public function testWorkersShareTheStoredSalt(): void {
        $store = new InMemorySaltStore();
        $a = $this->service($store, '2026-10-09');
        $b = $this->service($store, '2026-10-09');
        self::assertSame($a->currentSalt(), $b->currentSalt());
    }

    public function testPurgeKeepsTodayAndYesterdayOnly(): void {
        $store = new InMemorySaltStore();
        foreach (['2026-10-07', '2026-10-08', '2026-10-09'] as $day) {
            $store->getOrCreate($day, random_bytes(32));
        }
        $svc = $this->service($store, '2026-10-09');

        self::assertSame(1, $svc->purgeExpired());
        self::assertSame(['2026-10-08', '2026-10-09'], array_keys($store->salts));
    }

    private function service(SaltStore $store, string $day): FixedDaySaltService {
        $svc = new FixedDaySaltService();
        $svc->day = $day;
        Beans::inject($svc, 'store', $store);
        return $svc;
    }
}

final class FixedDaySaltService extends SaltServiceImpl {
    public string $day = '';

    protected function today(): string {
        return $this->day;
    }
}

final class InMemorySaltStore implements SaltStore {
    /** @var array<string, string> */
    public array $salts = [];
    public int $calls = 0;

    public function getOrCreate(string $day, string $candidate): string {
        $this->calls++;
        $this->salts[$day] ??= $candidate;
        ksort($this->salts);
        return $this->salts[$day];
    }

    public function deleteBefore(string $keepFromDay): int {
        $before = count($this->salts);
        $this->salts = array_filter($this->salts, static fn($d) => $d >= $keepFromDay, ARRAY_FILTER_USE_KEY);
        return $before - count($this->salts);
    }
}
