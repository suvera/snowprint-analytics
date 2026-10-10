<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\rollup;

use dev\suvera\snowprint\query\RetentionService;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\util\concurrent\LockException;

/** Deletes raw events past each site's retention once they are rolled up. */
#[Component]
class RetentionJob {
    #[Autowired]
    private RetentionService $retention;

    #[Scheduled(fixedDelay: 3600, initialDelay: 300)]
    public function sweep(): void {
        try {
            $this->retention->apply();
        } catch (LockException) {
            // another pod is sweeping right now
        }
    }
}
