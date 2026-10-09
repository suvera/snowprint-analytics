<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

use dev\suvera\snowprint\infra\Metrics;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\enums\RequestMethod;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\PostMapping;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;

/**
 * Tracker endpoint. Always answers fast: 202 once the event is buffered (or
 * deliberately skipped), 400 for malformed bodies. CORS is open because the
 * tracker posts from every tracked site's origin.
 */
#[RestController]
class EventController {
    use Wlf4p;

    public const RESULT_HEADER = 'X-Snowprint-Result';

    #[Autowired]
    private Tracker $tracker;

    #[Autowired]
    private ApplicationContext $ctx;

    #[Autowired]
    private Metrics $metrics;

    private ?bool $trustProxy = null;
    private ?bool $logClientIp = null;

    #[PostMapping(path: '/api/event')]
    public function track(HttpRequest $request): ResponseEntity {
        try {
            $payload = TrackingPayload::parse($request->getRawBody());
        } catch (InvalidPayload $e) {
            $this->metrics->ingest('invalid');
            return self::cors(ResponseEntity::badRequest()->withJson(['error' => $e->getMessage()]));
        }
        $this->trustProxy ??= Tracker::truthy($this->ctx->getPropertyStr('snowprint.ingest.trustProxy', 'false'));

        $client = ClientInfo::from($request, $this->trustProxy);
        $this->metrics->clientIp($client->ipSource, $client->privateIp);
        $this->logClientIp ??= Tracker::truthy($this->ctx->getPropertyStr('snowprint.ingest.logClientIp', 'false'));
        if ($this->logClientIp) {
            // Opt-in debugging (SNOWPRINT_LOG_CLIENT_IP): the only place a raw IP is logged.
            self::logInfo('Client IP debug', [
                'resolved' => $client->ip,
                'source' => $client->ipSource,
                'private' => $client->privateIp,
                'trust_proxy' => $this->trustProxy,
                'cf_connecting_ip' => $request->getFirstHeader('CF-Connecting-IP'),
                'x_forwarded_for' => $request->getFirstHeader('X-Forwarded-For'),
                'x_real_ip' => $request->getFirstHeader('X-Real-IP'),
                'socket' => $request->getRemoteAddr(),
            ]);
        }
        if ($client->privateIp) {
            // Never the address itself (privacy); the source is enough to debug proxy setups.
            self::logDebug('Client IP from ' . $client->ipSource . ' is private/loopback: local or internal network'
                . ($this->trustProxy ? '' : ' (SNOWPRINT_TRUST_PROXY is off, so proxy headers are ignored)'));
        }
        $result = $this->tracker->track($payload, $client);
        $this->metrics->ingest($result);
        return self::cors(ResponseEntity::accepted())->withHeader(self::RESULT_HEADER, $result);
    }

    #[RequestMapping(path: '/api/event', method: [RequestMethod::OPTIONS])]
    public function preflight(): ResponseEntity {
        return self::cors(ResponseEntity::noContent())
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    private static function cors(ResponseEntity $response): ResponseEntity {
        return $response->withHeader('Access-Control-Allow-Origin', '*');
    }
}
