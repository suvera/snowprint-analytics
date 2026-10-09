<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\infra;

use dev\suvera\snowprint\infra\PublicUrl;
use PHPUnit\Framework\TestCase;

final class PublicUrlTest extends TestCase {

    public function testNormalizes(): void {
        self::assertSame('', PublicUrl::normalize(''));
        self::assertSame('https://stats.example.com', PublicUrl::normalize(' https://stats.example.com/ '));
        self::assertSame('https://example.com/analytics', PublicUrl::normalize('https://example.com/analytics/'));
        self::assertSame('http://localhost:7669', PublicUrl::normalize('http://localhost:7669'));
    }

    public function testRejectsNonHttpUrls(): void {
        foreach (['stats.example.com', 'ftp://x.test', 'https://x.test/?a=1', 'javascript:alert(1)'] as $bad) {
            try {
                PublicUrl::normalize($bad);
                self::fail("accepted $bad");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
