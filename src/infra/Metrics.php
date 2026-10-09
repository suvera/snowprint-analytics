<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\io\metrics\prometheus\PrometheusMetricRegistry;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;

/**
 * Snowprint's Prometheus counters, exposed at /api/system/prometheus. Storage is APCu
 * (winter.prometheus.beanClass), shared by all workers without network calls.
 * Counters are registered on first use in each worker.
 */
#[Component]
class Metrics {

    public const INGEST_REQUESTS = 'snowprint_ingest_requests_total';
    public const EVENTS_WRITTEN = 'snowprint_events_written_total';
    public const INSERT_FAILURES = 'snowprint_event_insert_failures_total';
    public const EVENTS_DROPPED = 'snowprint_events_dropped_total';
    public const MCP_CALLS = 'snowprint_mcp_tool_calls_total';
    public const CLIENT_IP_SOURCES = 'snowprint_client_ip_source_total';

    #[Autowired]
    private PrometheusMetricRegistry $registry;

    private bool $registered = false;

    public function ingest(string $result): void {
        $this->incr(self::INGEST_REQUESTS, 1, [$result]);
    }

    public function eventsWritten(int $count): void {
        $this->incr(self::EVENTS_WRITTEN, $count);
    }

    public function insertFailed(): void {
        $this->incr(self::INSERT_FAILURES, 1);
    }

    public function eventDropped(): void {
        $this->incr(self::EVENTS_DROPPED, 1);
    }

    /** Where tracker requests' client IPs came from; never the IP itself. */
    public function clientIp(string $source, bool $private): void {
        $this->incr(self::CLIENT_IP_SOURCES, 1, [$source, $private ? 'private' : 'public']);
    }

    public function mcpCall(string $tool, bool $ok): void {
        $this->incr(self::MCP_CALLS, 1, [$tool, $ok ? 'ok' : 'error']);
    }

    private function incr(string $name, int $by, array $labels = []): void {
        if (!isset($this->registry) || $by <= 0) {
            return; // unit tests construct beans without a registry
        }
        try {
            $this->register();
            $this->registry->incrBy($name, $by, $labels);
        } catch (\Throwable) {
            // metrics must never break ingest or MCP
        }
    }

    private function register(): void {
        if ($this->registered) {
            return;
        }
        $this->registry->getOrRegisterCounter(self::INGEST_REQUESTS, 'Tracker requests by result', ['result']);
        $this->registry->getOrRegisterCounter(self::EVENTS_WRITTEN, 'Events written to PostgreSQL');
        $this->registry->getOrRegisterCounter(self::INSERT_FAILURES, 'Failed batch inserts (retried)');
        $this->registry->getOrRegisterCounter(self::EVENTS_DROPPED, 'Events dropped because the buffer was full');
        $this->registry->getOrRegisterCounter(self::MCP_CALLS, 'MCP tool calls', ['tool', 'result']);
        $this->registry->getOrRegisterCounter(self::CLIENT_IP_SOURCES,
            'Tracker requests by client IP source (cf-connecting-ip, x-forwarded-for, x-real-ip, socket)', ['source', 'scope']);
        $this->registered = true;
    }
}
