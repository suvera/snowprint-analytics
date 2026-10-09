<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\suvera\snowprint\site\InvalidInput;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;

/**
 * Helpers shared by the dashboard API controllers.
 *
 * CSRF: state-changing requests must carry "X-Snowprint: 1". Browsers cannot
 * add a custom header cross-site without a CORS preflight, which this API
 * never grants; the session cookie is also SameSite=Lax.
 */
trait UiApi {

    private static function requireUiHeader(HttpRequest $request): void {
        if ($request->getFirstHeader('X-Snowprint') !== '1') {
            throw new UiError(HttpStatus::$FORBIDDEN, 'missing X-Snowprint header');
        }
    }

    /** @return array<string, mixed> */
    private static function body(HttpRequest $request): array {
        $body = json_decode($request->getRawBody(), true);
        if (!is_array($body)) {
            throw new InvalidInput('request body must be a JSON object');
        }
        return $body;
    }

    /** @param \Closure(): ResponseEntity $action */
    private static function handle(\Closure $action): ResponseEntity {
        try {
            return $action();
        } catch (UiError $e) {
            return ResponseEntity::status($e->status)->withJson(['error' => $e->getMessage()]);
        } catch (InvalidInput $e) {
            return ResponseEntity::badRequest()->withJson(['error' => $e->getMessage()]);
        }
    }
}
