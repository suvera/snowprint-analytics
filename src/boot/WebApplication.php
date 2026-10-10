<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\boot;

use dev\winterframework\stereotype\cache\EnableCaching;
use dev\winterframework\stereotype\txn\EnableTransactionManagement;
use dev\winterframework\stereotype\WinterBootApplication;

/**
 * Web role: dashboard, management API, MCP endpoint, public share links.
 */
#[WinterBootApplication(
    configDirectory: [__DIR__ . '/../../config'],
    scanNamespaces: [
        ['dev\\suvera\\snowprint\\web\\', __DIR__ . '/../web'],
        ['dev\\suvera\\snowprint\\mcp\\', __DIR__ . '/../mcp'],
        ['dev\\suvera\\snowprint\\query\\', __DIR__ . '/../query'],
        ['dev\\suvera\\snowprint\\site\\', __DIR__ . '/../site'],
        ['dev\\suvera\\snowprint\\infra\\', __DIR__ . '/../infra'],
    ],
)]
#[EnableTransactionManagement]
#[EnableCaching]
class WebApplication extends BaseApplication {
}
