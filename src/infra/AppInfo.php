<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\actuator\InfoBuilder;
use dev\winterframework\actuator\InfoContributor;
use dev\winterframework\actuator\stereotype\InfoInformer;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;

/** Name and version on the actuator's /api/system/info (StatusController serves the same at /api/status). */
#[InfoInformer]
class AppInfo implements InfoContributor {

    #[Autowired]
    private ApplicationContext $ctx;

    public function contribute(InfoBuilder $info): void {
        $info->withDetail('name', 'snowprint')->withDetail('version', $this->ctx->getApplicationVersion());
    }
}
