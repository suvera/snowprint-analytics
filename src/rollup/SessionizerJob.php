<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\rollup;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\task\scheduling\stereotype\Scheduled;

/**
 * Assigns sessions to new events (SQL function snowprint_sessionize, decision
 * D5). Each call handles up to an hour of events; after downtime the job
 * catches up in several calls.
 */
#[Component]
class SessionizerJob {
    public const MAX_CALLS_PER_RUN = 48;

    #[Autowired]
    private PdbcTemplate $db;

    #[Scheduled(fixedDelay: 30, initialDelay: 20)]
    public function sessionize(): void {
        for ($i = 0; $i < self::MAX_CALLS_PER_RUN; $i++) {
            $result = $this->db->queryForMap('SELECT updated, caught_up FROM snowprint_sessionize()');
            if ((int) $result['updated'] < 0 || $result['caught_up'] === true || $result['caught_up'] === 't') {
                return;
            }
        }
    }
}
