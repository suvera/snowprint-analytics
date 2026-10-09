<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\ingest;

use dev\suvera\snowprint\ingest\InvalidPayload;
use dev\suvera\snowprint\ingest\TrackingPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackingPayloadTest extends TestCase {

    public function testParsesFullPayload(): void {
        $p = TrackingPayload::parse(json_encode([
            'd' => 'example.com', 'n' => 'signup', 'u' => 'https://example.com/a?b=1',
            'r' => 'https://news.ycombinator.com/', 't' => 'Home', 'w' => 1920, 'h' => 1080,
            'p' => ['plan' => 'pro', 'seats' => 3, 'trial' => true],
        ]));
        self::assertSame('example.com', $p->domain);
        self::assertSame('signup', $p->name);
        self::assertSame(1920, $p->screenWidth);
        self::assertSame(['plan' => 'pro', 'seats' => 3, 'trial' => true], $p->props);
    }

    public function testDefaultsToPageview(): void {
        $p = TrackingPayload::parse('{"d":"example.com","u":"https://example.com/"}');
        self::assertSame(TrackingPayload::PAGEVIEW, $p->name);
        self::assertSame([], $p->props);
        self::assertNull($p->screenWidth);
    }

    public function testDropsNestedAndOversizedProps(): void {
        $props = ['ok' => 'x', 'nested' => ['a' => 1], str_repeat('k', 65) => 'long key'];
        for ($i = 0; $i < 40; $i++) {
            $props["p$i"] = $i;
        }
        $p = TrackingPayload::parse(json_encode(['d' => 'a.com', 'u' => 'https://a.com/', 'p' => $props]));
        self::assertArrayNotHasKey('nested', $p->props);
        self::assertCount(TrackingPayload::MAX_PROPS, $p->props);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBodies(): iterable {
        yield 'not json' => ['hello'];
        yield 'json list' => ['[1,2]'];
        yield 'missing domain' => ['{"u":"https://a.com/"}'];
        yield 'missing url' => ['{"d":"a.com"}'];
        yield 'non-http url' => ['{"d":"a.com","u":"javascript:alert(1)"}'];
        yield 'too large' => ['{"d":"a.com","u":"https://a.com/","t":"' . str_repeat('x', 9000) . '"}'];
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidBodies(string $body): void {
        $this->expectException(InvalidPayload::class);
        TrackingPayload::parse($body);
    }
}
