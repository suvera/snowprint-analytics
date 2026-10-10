<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\suvera\snowprint\infra\RateLimiter;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use Throwable;

/**
 * Guards Winter Boot's MCP endpoint (/api/mcp): a valid API key as Bearer
 * token, and RATE_LIMIT_PER_MINUTE calls per key across all pods. Tools,
 * resources and prompts are service methods, so this is their only
 * route-level protection. Only POST is checked: Winter Boot answers 405 to
 * the other methods itself.
 *
 * Its beans are looked up per request, not injected when the interceptor is
 * registered: they use the database, and creating the connection pool
 * before Swoole forks the workers stops the server from starting.
 */
class McpAuthInterceptor implements HandlerInterceptor {

    public const RATE_LIMIT_PER_MINUTE = 120;

    public function __construct(private readonly ApplicationContext $ctx) {
    }

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return true;
        }
        $key = $this->ctx->beanByClass(McpCallers::class)->find($request);
        if ($key === null) {
            $response->withStatus(HttpStatus::$UNAUTHORIZED)
                ->withHeader('WWW-Authenticate', 'Bearer realm="snowprint"')
                ->withJson(self::error('missing or invalid API key: send "Authorization: Bearer <key>"'));
            return false;
        }
        if ($this->ctx->beanByClass(RateLimiter::class)->hit('mcp:' . $key->id, 60) > self::RATE_LIMIT_PER_MINUTE) {
            $response->withStatus(HttpStatus::$TOO_MANY_REQUESTS)
                ->withHeader('Retry-After', '60')
                ->withJson(self::error('rate limit exceeded'));
            return false;
        }
        return true;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response): void {
    }

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, ?Throwable $ex = null): void {
    }

    /** A JSON-RPC error without an id, as MCP clients expect for transport-level refusals. */
    private static function error(string $message): array {
        return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => $message]];
    }
}
