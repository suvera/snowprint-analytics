<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\query;

use dev\suvera\snowprint\query\Period;
use dev\suvera\snowprint\site\InvalidInput;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase {

    private \DateTimeImmutable $now;

    protected function setUp(): void {
        // 2026-10-09 10:00 UTC = 2026-10-09 12:00 in Berlin (CEST)
        $this->now = new \DateTimeImmutable('2026-10-09 10:00:00 UTC');
    }

    public function testTodayIsLocalMidnightToMidnight(): void {
        $p = Period::parse('today', 'Europe/Berlin', $this->now);
        self::assertSame('2026-10-08T22:00:00+00:00', $p->start->format('c'));
        self::assertSame('2026-10-09T22:00:00+00:00', $p->end->format('c'));
        self::assertSame(['from' => '2026-10-09', 'to' => '2026-10-09', 'timezone' => 'Europe/Berlin'], $p->describe());
    }

    public function testSevenDaysIncludesToday(): void {
        $p = Period::parse('7d', 'UTC', $this->now);
        self::assertSame(['from' => '2026-10-03', 'to' => '2026-10-09', 'timezone' => 'UTC'], $p->describe());
        self::assertSame(7, $p->days());
        self::assertSame('day', $p->defaultInterval());
    }

    public function testPreviousPeriodHasEqualLength(): void {
        $prev = Period::parse('7d', 'UTC', $this->now)->previous();
        self::assertSame(['from' => '2026-09-26', 'to' => '2026-10-02', 'timezone' => 'UTC'], $prev->describe());
    }

    public function testMonthAndLastMonth(): void {
        self::assertSame('2026-10-01', Period::parse('month', 'UTC', $this->now)->describe()['from']);
        self::assertSame(
            ['from' => '2026-09-01', 'to' => '2026-09-30', 'timezone' => 'UTC'],
            Period::parse('last_month', 'UTC', $this->now)->describe()
        );
        self::assertSame('month', Period::parse('12mo', 'UTC', $this->now)->defaultInterval());
    }

    public function testCustomRangeIsInclusive(): void {
        $p = Period::parse('2026-10-01..2026-10-03', 'UTC', $this->now);
        self::assertSame(3, $p->days());
        self::assertSame('hour', Period::parse('2026-10-01', 'UTC', $this->now)->defaultInterval());
    }

    public function testRejectsBadInput(): void {
        foreach (['forever', '2026-02-30', '2026-10-05..2026-10-01', '1000d', '0d'] as $bad) {
            try {
                Period::parse($bad, 'UTC', $this->now);
                self::fail("accepted $bad");
            } catch (InvalidInput) {
                self::assertTrue(true);
            }
        }
    }
}
