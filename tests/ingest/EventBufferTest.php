<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\ingest;

use dev\suvera\snowprint\infra\Metrics;
use dev\suvera\snowprint\ingest\EventBuffer;
use dev\suvera\snowprint\tests\support\Beans;
use dev\suvera\snowprint\tests\support\RecordingPdbcTemplate;
use PHPUnit\Framework\TestCase;

final class EventBufferTest extends TestCase {

    private RecordingPdbcTemplate $db;
    private EventBuffer $buffer;

    protected function setUp(): void {
        $this->db = new RecordingPdbcTemplate();
        $this->buffer = Beans::inject(Beans::inject(new EventBuffer(), 'db', $this->db), 'metrics', new Metrics());
    }

    public function testFlushWritesOneMultiRowInsert(): void {
        $this->buffer->add(self::row(1));
        $this->buffer->add(self::row(2));
        self::assertSame(2, $this->buffer->flush());

        self::assertCount(1, $this->db->updates);
        $update = $this->db->updates[0];
        self::assertStringStartsWith('INSERT INTO events (site_id, ts, visitor_hash,', $update['sql']);
        self::assertSame(2, substr_count($update['sql'], "decode(?, 'hex')"));
        self::assertCount(2 * count(EventBuffer::COLUMNS), $update['binds']);
        self::assertSame(0, $this->buffer->pendingCount());
    }

    public function testFullBatchFlushesImmediately(): void {
        for ($i = 0; $i < EventBuffer::MAX_BATCH; $i++) {
            $this->buffer->add(self::row($i));
        }
        self::assertCount(1, $this->db->updates);
        self::assertSame(0, $this->buffer->pendingCount());
    }

    public function testFailedInsertIsRetriedOnNextFlush(): void {
        $this->buffer->add(self::row(1));
        $this->db->failNextUpdate = new \RuntimeException('db down');
        self::assertSame(0, $this->buffer->flush());
        self::assertSame(1, $this->buffer->pendingCount());

        self::assertSame(1, $this->buffer->flush());
        self::assertCount(1, $this->db->updates);
    }

    public function testFlushWithNothingPendingDoesNothing(): void {
        self::assertSame(0, $this->buffer->flush());
        self::assertSame([], $this->db->updates);
    }

    /** @return array<string, mixed> */
    private static function row(int $i): array {
        return ['site_id' => 1, 'ts' => '2026-10-09 10:00:00+00', 'visitor_hash' => str_repeat('ab', 16),
            'name' => 'pageview', 'path' => "/p$i"];
    }
}
