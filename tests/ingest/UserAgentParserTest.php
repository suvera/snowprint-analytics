<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\ingest;

use dev\suvera\snowprint\ingest\UserAgentInfo;
use dev\suvera\snowprint\ingest\UserAgentParser;
use PHPUnit\Framework\TestCase;

final class UserAgentParserTest extends TestCase {

    public function testDesktopChrome(): void {
        $ua = UserAgentParser::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36');
        self::assertFalse($ua->bot);
        self::assertSame('Chrome', $ua->browser);
        self::assertSame('Windows', $ua->os);
        self::assertSame(UserAgentInfo::DESKTOP, $ua->device);
    }

    public function testIphoneSafariIsMobile(): void {
        $ua = UserAgentParser::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1');
        self::assertSame('Mobile Safari', $ua->browser);
        self::assertSame('iOS', $ua->os);
        self::assertSame(UserAgentInfo::MOBILE, $ua->device);
    }

    public function testIpadIsTablet(): void {
        $ua = UserAgentParser::parse('Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1');
        self::assertSame(UserAgentInfo::TABLET, $ua->device);
    }

    public function testCatchesBotsTheQuickFilterMisses(): void {
        // Looks like a browser but is Google's ad crawler.
        $ua = UserAgentParser::parse('Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36 (compatible; AdsBot-Google-Mobile; +http://www.google.com/mobile/adsbot.html)');
        self::assertTrue($ua->bot);
    }

    public function testResultsAreCached(): void {
        $agent = 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0';
        self::assertSame(UserAgentParser::parse($agent), UserAgentParser::parse($agent));
    }
}
