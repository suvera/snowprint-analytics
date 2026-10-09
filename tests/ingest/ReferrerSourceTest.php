<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\ingest;

use dev\suvera\snowprint\ingest\ReferrerSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferrerSourceTest extends TestCase {

    /** @return iterable<array{?string, ?string, ?string}> */
    public static function cases(): iterable {
        yield 'google ccTLD' => ['www.google.co.uk', null, 'Google'];
        yield 'hacker news' => ['news.ycombinator.com', null, 'Hacker News'];
        yield 'old reddit' => ['old.reddit.com', null, 'Reddit'];
        yield 't.co' => ['t.co', null, 'X (Twitter)'];
        yield 'facebook link shim' => ['l.facebook.com', null, 'Facebook'];
        yield 'gmail is email, not google' => ['mail.google.com', null, 'Email'];
        yield 'unknown host kept' => ['blog.example.org', null, 'blog.example.org'];
        yield 'utm_source wins' => ['www.google.com', 'newsletter', 'newsletter'];
        yield 'tagged direct link' => [null, 'qr-code', 'qr-code'];
        yield 'direct' => [null, null, null];
        yield 'lookalike is not google' => ['notgoogle.com', null, 'notgoogle.com'];
    }

    #[DataProvider('cases')]
    public function testNames(?string $host, ?string $utm, ?string $expected): void {
        self::assertSame($expected, ReferrerSource::name($host, $utm));
    }
}
