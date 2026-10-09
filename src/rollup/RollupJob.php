<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\rollup;

use dev\suvera\snowprint\query\RollupBuilder;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\util\log\Wlf4p;

/**
 * Rolls up complete site-local days into rollup_daily (RollupBuilder). After
 * downtime or an upgrade it catches up a bounded number of days per run.
 */
#[Component]
class RollupJob {
    use Wlf4p;

    #[Autowired]
    private RollupBuilder $builder;

    #[Scheduled(fixedDelay: 600, initialDelay: 60)]
    public function rollup(): void {
        try {
            $days = $this->builder->rollPending();
            if ($days > 0) {
                self::logInfo("Rolled up $days site-day(s)");
            }
        } catch (\Throwable $e) {
            self::logException($e, 'Rollup failed. ');
        }
    }
}
