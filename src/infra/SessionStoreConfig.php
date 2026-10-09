<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\Configuration;
use dev\winterframework\web\session\PdbcSessionStore;

/**
 * Dashboard sessions live in PostgreSQL (Winter Boot's PdbcSessionStore,
 * table winter_sessions), so they survive restarts and work across replicas.
 */
#[Configuration]
class SessionStoreConfig {

    public const TTL_SECONDS = 30 * 86400;

    #[Bean]
    public function sessionStore(PdbcTemplate $db): \SessionHandlerInterface {
        return new PdbcSessionStore($db, 'winter_sessions', self::TTL_SECONDS);
    }
}
