<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\privacy;

use dev\suvera\snowprint\privacy\VisitorHasher;
use PHPUnit\Framework\TestCase;

final class VisitorHasherTest extends TestCase {

    private const SALT_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SALT_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const UA = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';

    public function testHashIsSixteenBytesAndStable(): void {
        $h = VisitorHasher::hash(self::SALT_A, 1, '203.0.113.7', self::UA);
        self::assertSame(16, strlen($h));
        self::assertSame($h, VisitorHasher::hash(self::SALT_A, 1, '203.0.113.7', self::UA));
    }

    public function testNewSaltBreaksLinkage(): void {
        self::assertNotSame(
            VisitorHasher::hash(self::SALT_A, 1, '203.0.113.7', self::UA),
            VisitorHasher::hash(self::SALT_B, 1, '203.0.113.7', self::UA)
        );
    }

    public function testSameVisitorDiffersAcrossSites(): void {
        self::assertNotSame(
            VisitorHasher::hash(self::SALT_A, 1, '203.0.113.7', self::UA),
            VisitorHasher::hash(self::SALT_A, 2, '203.0.113.7', self::UA)
        );
    }

    public function testIpAndUserAgentBothMatter(): void {
        $base = VisitorHasher::hash(self::SALT_A, 1, '203.0.113.7', self::UA);
        self::assertNotSame($base, VisitorHasher::hash(self::SALT_A, 1, '203.0.113.8', self::UA));
        self::assertNotSame($base, VisitorHasher::hash(self::SALT_A, 1, '203.0.113.7', self::UA . ' X'));
    }

    public function testHashDoesNotContainTheInputs(): void {
        $h = VisitorHasher::hash(self::SALT_A, 1, '203.0.113.7', self::UA);
        self::assertStringNotContainsString('203.0.113.7', $h);
        self::assertStringNotContainsString('Firefox', $h);
    }
}
