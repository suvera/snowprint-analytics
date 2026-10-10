<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\boot;

use dev\winterframework\stereotype\task\EnableScheduling;
use dev\winterframework\stereotype\cache\EnableCaching;
use dev\winterframework\stereotype\txn\EnableTransactionManagement;
use dev\winterframework\stereotype\WinterBootApplication;

/**
 * Single-container tier: web, ingest and worker roles in one Swoole process.
 */
#[WinterBootApplication(
    configDirectory: [__DIR__ . '/../../config'],
    scanNamespaces: [
        ['dev\\suvera\\snowprint\\', __DIR__ . '/..'],
    ],
)]
#[EnableTransactionManagement]
#[EnableCaching]
#[EnableScheduling]
class AllRolesApplication extends BaseApplication {
}
