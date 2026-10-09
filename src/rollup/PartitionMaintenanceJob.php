<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\rollup;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\util\log\Wlf4p;

/**
 * Keeps monthly `events` partitions created ahead of time, so ingest never
 * falls into the default partition. The SQL function is idempotent, so
 * running it on several workers at once is harmless.
 */
#[Component]
class PartitionMaintenanceJob {
    use Wlf4p;

    public const MONTHS_AHEAD = 2;

    #[Autowired]
    private PdbcTemplate $db;

    #[Scheduled(fixedDelay: 3600, initialDelay: 30)]
    public function ensurePartitions(): void {
        try {
            $created = (int) $this->db->queryForScalar(
                'SELECT snowprint_ensure_event_partitions(?)',
                [self::MONTHS_AHEAD]
            );
            if ($created > 0) {
                self::logInfo("Created $created events partition(s)");
            }
        } catch (\Throwable $e) {
            self::logException($e, 'Events partition maintenance failed. ');
        }
    }
}
