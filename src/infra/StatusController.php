<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;

/**
 * /api/status: name and version. Served by every role (infra is scanned by all
 * starters), so it doubles as the Kubernetes liveness probe: it never touches
 * PostgreSQL.
 */
#[RestController]
class StatusController {

    #[Autowired]
    private ApplicationContext $ctx;

    #[GetMapping(path: '/api/status')]
    public function status(): array {
        return [
            'name' => 'snowprint',
            'version' => $this->ctx->getApplicationVersion(),
        ];
    }
}
