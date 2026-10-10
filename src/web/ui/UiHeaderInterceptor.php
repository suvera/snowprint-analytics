<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use Throwable;

/**
 * CSRF guard for the dashboard and share-link APIs: every request except
 * GET/HEAD must carry "X-Snowprint: 1". Browsers cannot add a custom header
 * cross-site without a CORS preflight, which this API never grants; the
 * session cookie is also SameSite=Lax.
 */
class UiHeaderInterceptor implements HandlerInterceptor {

    public const HEADER = 'X-Snowprint';
    private const SAFE_METHODS = ['GET', 'HEAD'];

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)
            || $request->getFirstHeader(self::HEADER) === '1') {
            return true;
        }
        $response->withStatus(HttpStatus::$FORBIDDEN)->withJson(['error' => 'missing X-Snowprint header']);
        return false;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response): void {
    }

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, ?Throwable $ex = null): void {
    }
}
