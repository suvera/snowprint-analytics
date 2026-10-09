<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

use dev\suvera\snowprint\infra\Metrics;
use dev\winterframework\core\app\WorkerStartEvent;
use dev\winterframework\core\app\WorkerStopEvent;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\OnWorkerStart;
use dev\winterframework\stereotype\OnWorkerStop;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Coroutine;
use Swoole\Timer;

/**
 * Per-worker event buffer (decision D8). Requests append in memory and return
 * at once; a Swoole timer flushes every FLUSH_INTERVAL_MS, and a full batch is
 * flushed straight away, each as one multi-row INSERT. Winter Boot's worker
 * hooks start the timer in every worker and flush what is left when it stops.
 *
 * Generic enough to upstream into Winter Boot as a batching helper.
 */
#[Component]
#[OnWorkerStart]
#[OnWorkerStop]
class EventBuffer implements WorkerStartEvent, WorkerStopEvent {
    use Wlf4p;

    public const FLUSH_INTERVAL_MS = 200;
    public const MAX_BATCH = 500;
    public const MAX_PENDING = 50_000;

    /** Column => SQL placeholder. */
    public const COLUMNS = [
        'site_id' => '?', 'ts' => '?', 'visitor_hash' => "decode(?, 'hex')", 'name' => '?',
        'hostname' => '?', 'path' => '?', 'title' => '?', 'referrer_source' => '?',
        'referrer_host' => '?', 'utm_source' => '?', 'utm_medium' => '?', 'utm_campaign' => '?',
        'utm_term' => '?', 'utm_content' => '?', 'country' => '?', 'region' => '?', 'city' => '?',
        'browser' => '?', 'browser_version' => '?', 'os' => '?', 'os_version' => '?',
        'device' => '?', 'screen_w' => '?', 'screen_h' => '?', 'props' => '?::jsonb',
    ];

    #[Autowired]
    private PdbcTemplate $db;

    #[Autowired]
    private Metrics $metrics;

    /** @var list<array<string, mixed>> */
    private array $pending = [];
    private int $dropped = 0;

    /** @param array<string, mixed> $row keyed by COLUMNS; visitor_hash as hex */
    public function add(array $row): void {
        if (count($this->pending) >= self::MAX_PENDING) {
            $this->metrics->eventDropped();
            if ($this->dropped++ % 1000 === 0) {
                self::logWarning('Event buffer full, dropping events', ['dropped' => $this->dropped]);
            }
            return;
        }
        $this->pending[] = $row;
        if (count($this->pending) >= self::MAX_BATCH) {
            Coroutine::getCid() > 0 ? Coroutine::create(fn() => $this->flush()) : $this->flush();
        }
    }

    public function pendingCount(): int {
        return count($this->pending);
    }

    /** Writes everything pending. Returns rows written. */
    public function flush(): int {
        if ($this->pending === []) {
            return 0;
        }
        $batch = $this->pending;
        $this->pending = [];

        $written = 0;
        foreach (array_chunk($batch, self::MAX_BATCH) as $i => $chunk) {
            try {
                $this->insert($chunk);
                $written += count($chunk);
                $this->metrics->eventsWritten(count($chunk));
            } catch (\Throwable $e) {
                $this->metrics->insertFailed();
                self::logException($e, 'Event batch insert failed; retrying with the next flush. ');
                $unsent = array_merge(...array_slice(array_chunk($batch, self::MAX_BATCH), $i));
                $this->pending = array_slice(array_merge($unsent, $this->pending), 0, self::MAX_PENDING);
                break;
            }
        }
        return $written;
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(array $rows): void {
        $tuple = '(' . implode(', ', self::COLUMNS) . ')';
        $sql = 'INSERT INTO events (' . implode(', ', array_keys(self::COLUMNS)) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $tuple));
        $binds = [];
        foreach ($rows as $row) {
            foreach (array_keys(self::COLUMNS) as $column) {
                $binds[] = $row[$column] ?? null;
            }
        }
        $this->db->update($sql, $binds);
    }

    public function onWorkerStart(int $workerId): void {
        Timer::tick(self::FLUSH_INTERVAL_MS, fn() => $this->flush());
    }

    public function onWorkerStop(int $workerId): void {
        $written = $this->flush();
        if ($written > 0) {
            self::logInfo("Flushed $written buffered event(s) as worker $workerId stopped");
        }
    }
}
