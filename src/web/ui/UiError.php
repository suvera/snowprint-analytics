<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\winterframework\web\http\HttpStatus;

/** An API error with its HTTP status; the message is shown to the user. */
final class UiError extends \RuntimeException {

    public function __construct(public readonly HttpStatus $status, string $message) {
        parent::__construct($message);
    }
}
