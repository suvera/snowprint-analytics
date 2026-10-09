<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\site;

use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\SiteService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteServiceTest extends TestCase {

    /** @return iterable<array{string, string}> */
    public static function domains(): iterable {
        yield ['example.com', 'example.com'];
        yield ['  Example.COM ', 'example.com'];
        yield ['www.example.com', 'example.com'];
        yield ['https://www.example.com/pricing?x=1', 'example.com'];
        yield ['blog.example.co.uk', 'blog.example.co.uk'];
        yield ['example.com.', 'example.com'];
    }

    #[DataProvider('domains')]
    public function testNormalizesDomains(string $input, string $expected): void {
        self::assertSame($expected, SiteService::normalizeDomain($input));
    }

    public function testAcceptsCurrentTimezoneNames(): void {
        foreach (['UTC', 'Asia/Kolkata', 'Europe/Berlin', 'America/New_York'] as $tz) {
            self::assertSame($tz, SiteService::timezone($tz));
        }
    }

    public function testMapsLegacyBrowserNamesToCurrentOnes(): void {
        self::assertSame('Asia/Kolkata', SiteService::timezone('Asia/Calcutta'));
        self::assertSame('Europe/Kyiv', SiteService::timezone('Europe/Kiev'));
    }

    public function testEveryAliasTargetExists(): void {
        foreach (SiteService::TIMEZONE_ALIASES as $legacy => $current) {
            self::assertContains($current, \DateTimeZone::listIdentifiers(), "$legacy -> $current");
        }
    }

    public function testRejectsUnknownTimezones(): void {
        foreach (['Mars/Olympus_Mons', '+05:30', '', 'asia/kolkata'] as $tz) {
            try {
                SiteService::timezone($tz);
                self::fail("accepted $tz");
            } catch (InvalidInput) {
                self::assertTrue(true);
            }
        }
    }

    /** @return iterable<array{string}> */
    public static function invalid(): iterable {
        yield [''];
        yield ['localhost'];
        yield ['exa mple.com'];
        yield ['"quoted".com'];
        yield ['-bad.com'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidDomains(string $input): void {
        $this->expectException(InvalidInput::class);
        SiteService::normalizeDomain($input);
    }
}
