<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\query;

use dev\suvera\snowprint\query\Filters;
use dev\suvera\snowprint\site\InvalidInput;
use PHPUnit\Framework\TestCase;

final class FiltersTest extends TestCase {

    public function testEmptyFiltersMatchEverything(): void {
        self::assertSame(['TRUE', []], Filters::none()->toSql());
    }

    public function testBuildsBoundConditions(): void {
        [$sql, $binds] = Filters::of(['page' => '/blog/*', 'country' => 'DE', 'source' => 'Direct / None'])->toSql();
        self::assertSame('country = ? AND path LIKE ? AND referrer_source IS NULL', $sql);
        self::assertSame(['DE', '/blog/%'], $binds);
    }

    public function testLikeMetacharactersInPathsAreLiteral(): void {
        [, $binds] = Filters::of(['page' => '/100%_off/*'])->toSql();
        self::assertSame(['/100\\%\\_off/%'], $binds);
    }

    public function testUnknownDimensionIsRejected(): void {
        $this->expectException(InvalidInput::class);
        Filters::of(['path; DROP TABLE events' => 'x']);
    }
}
