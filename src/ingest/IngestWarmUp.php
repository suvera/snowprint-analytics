<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

use dev\winterframework\core\app\WorkerStartEvent;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\OnWorkerStart;

/**
 * Prepares each worker before it serves tracker requests.
 */
#[Component]
#[OnWorkerStart]
class IngestWarmUp implements WorkerStartEvent {

    public function onWorkerStart(int $workerId): void {
        UserAgentParser::warmUp();
    }
}
