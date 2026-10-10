<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\suvera\snowprint\infra\RateLimiter;
use dev\suvera\snowprint\site\ApiKey;
use dev\suvera\snowprint\site\ApiKeyService;
use dev\winterframework\enums\RequestMethod;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\PostMapping;
use dev\winterframework\stereotype\web\RequestMapping;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;

/**
 * MCP Streamable HTTP endpoint (PRD §7.2). Every POST is answered with a
 * plain JSON response; the server does not offer the optional GET event
 * stream (405), which tool calls do not need.
 *
 * Auth: "Authorization: Bearer <API key>" (bin/console.sh key:create).
 */
#[RestController]
class McpController {

    public const RATE_LIMIT_PER_MINUTE = 120;
    public const MAX_BODY_BYTES = 1_048_576;

    #[Autowired]
    private McpServer $server;

    #[Autowired]
    private ApiKeyService $keys;

    #[Autowired]
    private RateLimiter $limiter;

    #[PostMapping(path: '/api/mcp')]
    public function post(HttpRequest $request): ResponseEntity {
        $key = $this->authenticate($request);
        if ($key === null) {
            return ResponseEntity::unauthorized()
                ->withHeader('WWW-Authenticate', 'Bearer realm="snowprint"')
                ->withJson(McpServer::error(null, McpServer::INVALID_REQUEST,
                    'missing or invalid API key: send "Authorization: Bearer <key>"'));
        }
        if (!$this->allow($key)) {
            return ResponseEntity::status(HttpStatus::$TOO_MANY_REQUESTS)
                ->withHeader('Retry-After', '60')
                ->withJson(McpServer::error(null, McpServer::INVALID_REQUEST, 'rate limit exceeded'));
        }

        $body = $request->getRawBody();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return ResponseEntity::badRequest()->withJson(McpServer::error(null, McpServer::INVALID_REQUEST, 'request too large'));
        }
        $message = json_decode($body, true);
        if ($message === null && json_last_error() !== JSON_ERROR_NONE) {
            return ResponseEntity::badRequest()->withJson(McpServer::error(null, McpServer::PARSE_ERROR, 'parse error'));
        }

        // A JSON array is a batch (allowed by protocol 2025-03-26).
        $batch = is_array($message) && array_is_list($message) && $message !== [];
        $responses = [];
        foreach ($batch ? $message : [$message] as $item) {
            $response = $this->server->handle($item, $key);
            if ($response !== null) {
                $responses[] = $response;
            }
        }
        if ($responses === []) {
            return ResponseEntity::accepted(); // only notifications
        }
        return ResponseEntity::ok()->withJson($batch ? $responses : $responses[0]);
    }

    #[RequestMapping(path: '/api/mcp', method: [RequestMethod::GET, RequestMethod::DELETE])]
    public function noStream(): ResponseEntity {
        return ResponseEntity::status(HttpStatus::$METHOD_NOT_ALLOWED)
            ->withHeader('Allow', 'POST');
    }

    private function authenticate(HttpRequest $request): ?ApiKey {
        $header = $request->getFirstHeader('Authorization') ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }
        return $this->keys->authenticate($m[1]);
    }

    /** One-minute window per key, shared by all workers and pods. */
    private function allow(ApiKey $key): bool {
        return $this->limiter->hit('mcp:' . $key->id, 60) <= self::RATE_LIMIT_PER_MINUTE;
    }
}
