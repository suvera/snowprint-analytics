<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\query;

use dev\suvera\snowprint\query\RollupBuilder;
use PHPUnit\Framework\TestCase;

final class RollupBuilderTest extends TestCase {

    private static function sums(int $visitors): array {
        return ['visitors' => $visitors, 'visits' => $visitors, 'pageviews' => 2 * $visitors, 'events' => 0,
            'bounces' => 1, 'duration_sum' => 10];
    }

    public function testKeepsSmallDaysWhole(): void {
        $rows = ['/a' => self::sums(1), '/b' => self::sums(2)];
        self::assertSame($rows, RollupBuilder::capped($rows, 3));
    }

    public function testFoldsTheTailIntoOther(): void {
        $rows = ['/a' => self::sums(1), '/b' => self::sums(5), '/c' => self::sums(2), '404' => self::sums(3)];
        $capped = RollupBuilder::capped($rows, 3);
        self::assertSame(['/b', '404', RollupBuilder::OTHER], array_map('strval', array_keys($capped)));
        self::assertSame(['visitors' => 3, 'visits' => 3, 'pageviews' => 6, 'events' => 0, 'bounces' => 2,
            'duration_sum' => 20], $capped[RollupBuilder::OTHER]);
    }
}
