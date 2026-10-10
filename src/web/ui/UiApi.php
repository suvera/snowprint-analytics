<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\suvera\snowprint\site\InvalidInput;
use dev\winterframework\web\http\HttpRequest;

/**
 * Helpers shared by the JSON API controllers (dashboard and operator console).
 * Errors need no wrapper: InvalidInput and UiError are HttpRestExceptions,
 * which Winter Boot answers with their status and message. The CSRF header
 * check is UiHeaderInterceptor's job.
 */
trait UiApi {

    /** @return array<string, mixed> */
    private static function body(HttpRequest $request): array {
        $body = json_decode($request->getRawBody(), true);
        if (!is_array($body)) {
            throw new InvalidInput('request body must be a JSON object');
        }
        return $body;
    }
}
