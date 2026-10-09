<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\ingest;

use dev\suvera\snowprint\ingest\PageUrl;
use PHPUnit\Framework\TestCase;

final class PageUrlTest extends TestCase {

    public function testKeepsPathAndUtmOnly(): void {
        $u = PageUrl::parse('https://Example.com/pricing?email=a@b.c&utm_source=hn&utm_campaign=launch#top');
        self::assertSame('example.com', $u->hostname);
        self::assertSame('/pricing', $u->path);
        self::assertSame(['utm_source' => 'hn', 'utm_campaign' => 'launch'], $u->utm);
    }

    public function testEmptyPathIsRoot(): void {
        self::assertSame('/', PageUrl::parse('https://example.com')->path);
    }

    public function testReferrerHost(): void {
        self::assertSame('news.ycombinator.com', PageUrl::referrerHost('https://news.ycombinator.com/item?id=1', 'example.com'));
        self::assertNull(PageUrl::referrerHost('https://www.example.com/other', 'example.com'), 'same site');
        self::assertNull(PageUrl::referrerHost(null, 'example.com'));
        self::assertNull(PageUrl::referrerHost('not a url', 'example.com'));
    }
}
