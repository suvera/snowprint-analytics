<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\pdbc\DataSource;
use dev\winterframework\pdbc\lock\PdoLockManager;
use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\Configuration;
use dev\winterframework\util\concurrent\LockManager;

/**
 * Cluster-wide #[Lockable] locks: leases in PostgreSQL (table winter_locks,
 * migration 011), visible to every pod at once. Use with
 * #[Lockable(name: ..., ttlSeconds: ..., lockManager: LockConfig::PG)].
 */
#[Configuration]
class LockConfig {

    public const PG = 'pgLockManager';

    #[Bean(self::PG)]
    public function pgLockManager(DataSource $dataSource): LockManager {
        return new PdoLockManager($dataSource, createTable: false);
    }
}
