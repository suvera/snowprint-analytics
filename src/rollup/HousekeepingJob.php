<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\rollup;

use dev\suvera\snowprint\infra\RateLimiter;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\util\log\Wlf4p;

/**
 * Deletes expired dashboard and share-link sessions and old rate-limit
 * counters. PdbcSessionStore only skips expired rows on read; without this
 * winter_sessions grows forever.
 */
#[Component]
class HousekeepingJob {
    use Wlf4p;

    #[Autowired]
    private \SessionHandlerInterface $sessions;

    #[Autowired]
    private RateLimiter $limiter;

    #[Scheduled(fixedDelay: 3600, initialDelay: 120)]
    public function purge(): void {
        $sessions = (int) $this->sessions->gc(0); // PdbcSessionStore ignores the argument
        $counters = $this->limiter->purge();
        if ($sessions > 0 || $counters > 0) {
            self::logInfo("Deleted $sessions expired session(s) and $counters old rate-limit counter(s)");
        }
    }
}
