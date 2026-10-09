<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\ingest;

use dev\suvera\snowprint\ingest\BotFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BotFilterTest extends TestCase {

    /** @return iterable<string, array{string}> */
    public static function browsers(): iterable {
        yield 'chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'];
        yield 'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0'];
        yield 'safari ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'];
        yield 'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0'];
        yield 'samsung' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36'];
    }

    /** @return iterable<string, array{string}> */
    public static function bots(): iterable {
        yield 'empty' => [''];
        yield 'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'];
        yield 'headless chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/141.0.0.0 Safari/537.36'];
        yield 'curl' => ['curl/8.9.1'];
        yield 'python' => ['python-requests/2.32.3'];
        yield 'uptime robot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'];
        yield 'facebook preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'];
        yield 'lighthouse' => ['Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse'];
        yield 'slack' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'];
    }

    #[DataProvider('browsers')]
    public function testBrowsersPass(string $ua): void {
        self::assertFalse(BotFilter::isBot($ua));
    }

    #[DataProvider('bots')]
    public function testBotsAreDropped(string $ua): void {
        self::assertTrue(BotFilter::isBot($ua));
    }
}
