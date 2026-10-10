<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\admin;

use dev\suvera\snowprint\infra\OperatorToken;
use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use Throwable;

/**
 * Guards /api/admin: callers must be on the loopback interface (i.e. inside
 * the container) and present the operator token. Used by bin/console.sh.
 */
class OperatorInterceptor implements HandlerInterceptor {

    public const TOKEN_HEADER = 'X-Operator-Token';
    private const LOOPBACK = ['127.0.0.1', '::1'];

    public function __construct(private readonly OperatorToken $token) {
    }

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool {
        $local = in_array($request->getRemoteAddr(), self::LOOPBACK, true);
        if ($local && $this->token->matches($request->getFirstHeader(self::TOKEN_HEADER))) {
            return true;
        }
        $response->withStatus(HttpStatus::$FORBIDDEN)
            ->withJson(['error' => 'operator access only: run bin/console.sh inside the container']);
        return false;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response): void {
    }

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, ?Throwable $ex = null): void {
    }
}
