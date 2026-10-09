<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\ingest;

use dev\suvera\snowprint\ingest\ClientIpResolver;
use PHPUnit\Framework\TestCase;

final class ClientIpResolverTest extends TestCase {

    private const DOCKER_GATEWAY = '172.17.0.1';

    /** @param array<string, string> $headers */
    private static function resolve(array $headers, ?string $remote = self::DOCKER_GATEWAY, bool $trust = true): array {
        return ClientIpResolver::resolve(static fn(string $name) => $headers[$name] ?? null, $remote, $trust);
    }

    public function testCloudflareHeaderWins(): void {
        $r = self::resolve(['CF-Connecting-IP' => '81.2.69.142', 'X-Forwarded-For' => '203.0.113.9', 'X-Real-IP' => '198.51.100.7']);
        self::assertSame(['ip' => '81.2.69.142', 'source' => 'cf-connecting-ip', 'private' => false], $r);
    }

    public function testForwardedForTakesLeftmostTrimmedAddress(): void {
        $r = self::resolve(['X-Forwarded-For' => '  203.0.113.9 , 10.0.0.2, 172.17.0.1']);
        self::assertSame(['ip' => '203.0.113.9', 'source' => 'x-forwarded-for', 'private' => false], $r);
    }

    public function testForwardedForSkipsGarbageEntries(): void {
        self::assertSame('203.0.113.9', self::resolve(['X-Forwarded-For' => 'unknown, 203.0.113.9'])['ip']);
    }

    public function testRealIpIsNextInLine(): void {
        $r = self::resolve(['X-Real-IP' => '198.51.100.7']);
        self::assertSame(['ip' => '198.51.100.7', 'source' => 'x-real-ip', 'private' => false], $r);
    }

    public function testFallsBackToSocketAndFlagsPrivateAddresses(): void {
        $r = self::resolve([]);
        self::assertSame(['ip' => self::DOCKER_GATEWAY, 'source' => 'socket', 'private' => true], $r);
    }

    public function testInvalidCloudflareValueFallsThrough(): void {
        $r = self::resolve(['CF-Connecting-IP' => 'not-an-ip', 'X-Forwarded-For' => '203.0.113.9']);
        self::assertSame('x-forwarded-for', $r['source']);
    }

    public function testHeadersAreIgnoredWithoutATrustedProxy(): void {
        $r = self::resolve(['CF-Connecting-IP' => '81.2.69.142', 'X-Forwarded-For' => '203.0.113.9'], '198.51.100.20', false);
        self::assertSame(['ip' => '198.51.100.20', 'source' => 'socket', 'private' => false], $r);
    }

    public function testPortsAndIpv6(): void {
        self::assertSame('203.0.113.9', self::resolve(['X-Forwarded-For' => '203.0.113.9:51234'])['ip']);
        self::assertSame('2001:db8::1', self::resolve(['X-Forwarded-For' => '[2001:DB8::1]:443'])['ip']);
        self::assertSame('2001:db8::1', self::resolve(['CF-Connecting-IP' => '2001:db8::1'])['ip']);
    }

    public function testPrivateAndLoopbackRanges(): void {
        foreach (['127.0.0.1', '::1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.65.1', 'fd00::1'] as $ip) {
            self::assertTrue(ClientIpResolver::isPrivate($ip), $ip);
        }
        foreach (['81.2.69.142', '172.32.0.1', '2001:4860::8888'] as $ip) {
            self::assertFalse(ClientIpResolver::isPrivate($ip), $ip);
        }
    }

    public function testNoAddressAtAll(): void {
        self::assertSame(['ip' => '', 'source' => 'none', 'private' => false], self::resolve([], null));
    }
}
