<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;

/**
 * Fixed-window counters in PostgreSQL (table rate_limits), shared by every
 * worker and pod (invariant 3: no shared state in worker memory). A window
 * starts with its first hit and lasts $windowSeconds.
 */
#[Component]
class RateLimiter {

    /** Rows older than this are deleted by purge(); longer than any window in use. */
    public const KEEP_SECONDS = 86400;

    #[Autowired]
    private PdbcTemplate $db;

    /** Counts one hit; returns the hits in the current window, this one included. */
    public function hit(string $bucket, int $windowSeconds, ?int $now = null): int {
        $now ??= time();
        return (int) $this->db->queryForScalar(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
             ON CONFLICT (bucket) DO UPDATE SET
                 hits = CASE WHEN rate_limits.window_start <= EXCLUDED.window_start - ?
                             THEN 1 ELSE rate_limits.hits + 1 END,
                 window_start = CASE WHEN rate_limits.window_start <= EXCLUDED.window_start - ?
                             THEN EXCLUDED.window_start ELSE rate_limits.window_start END
             RETURNING hits',
            [$bucket, $now, $windowSeconds, $windowSeconds]
        );
    }

    /** Hits in the current window, without counting one. */
    public function hits(string $bucket, int $windowSeconds, ?int $now = null): int {
        $row = $this->db->queryForList('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start > ?',
            [$bucket, ($now ?? time()) - $windowSeconds])[0] ?? null;
        return $row === null ? 0 : (int) $row['hits'];
    }

    public function clear(string $bucket): void {
        $this->db->update('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
    }

    /** @return int rows deleted */
    public function purge(?int $now = null): int {
        return $this->db->update('DELETE FROM rate_limits WHERE window_start < ?', [($now ?? time()) - self::KEEP_SECONDS]);
    }
}
