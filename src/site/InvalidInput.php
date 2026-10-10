<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\winterframework\exception\HttpRestException;
use dev\winterframework\web\http\HttpStatus;

/**
 * User-supplied value rejected; the message is safe to show. Thrown out of a
 * REST handler, Winter Boot answers 400 with the message in "error" and logs
 * one INFO line (no stack trace, no message).
 */
final class InvalidInput extends HttpRestException {

    public function __construct(string $message) {
        parent::__construct(HttpStatus::$BAD_REQUEST, $message);
    }
}
