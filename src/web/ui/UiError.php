<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\winterframework\exception\HttpRestException;

/**
 * An API error with its HTTP status; the message is shown to the user.
 * Winter Boot's error controller renders it as {"status": ..., "error": message}.
 */
final class UiError extends HttpRestException {
}
