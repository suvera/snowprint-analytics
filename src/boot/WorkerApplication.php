<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\boot;

use dev\winterframework\stereotype\task\EnableScheduling;
use dev\winterframework\stereotype\txn\EnableTransactionManagement;
use dev\winterframework\stereotype\WinterBootApplication;

/**
 * Worker role: rollups, partition upkeep, retention sweeps, salt expiry,
 * email reports. Every #[Scheduled] job lives in a namespace scanned here.
 */
#[WinterBootApplication(
    configDirectory: [__DIR__ . '/../../config'],
    scanNamespaces: [
        ['dev\\suvera\\snowprint\\rollup\\', __DIR__ . '/../rollup'],
        ['dev\\suvera\\snowprint\\query\\', __DIR__ . '/../query'],
        ['dev\\suvera\\snowprint\\privacy\\', __DIR__ . '/../privacy'],
        ['dev\\suvera\\snowprint\\site\\', __DIR__ . '/../site'],
        ['dev\\suvera\\snowprint\\infra\\', __DIR__ . '/../infra'],
    ],
)]
#[EnableTransactionManagement]
#[EnableScheduling]
class WorkerApplication extends BaseApplication {
}
