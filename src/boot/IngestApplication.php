<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\boot;

use dev\winterframework\stereotype\WinterBootApplication;

/**
 * Ingest role: tracking endpoints, batching, enrichment, bot filtering.
 */
#[WinterBootApplication(
    configDirectory: [__DIR__ . '/../../config'],
    scanNamespaces: [
        ['dev\\suvera\\snowprint\\ingest\\', __DIR__ . '/../ingest'],
        ['dev\\suvera\\snowprint\\privacy\\', __DIR__ . '/../privacy'],
        ['dev\\suvera\\snowprint\\site\\', __DIR__ . '/../site'],
        ['dev\\suvera\\snowprint\\infra\\', __DIR__ . '/../infra'],
    ],
)]
class IngestApplication extends BaseApplication {
}
