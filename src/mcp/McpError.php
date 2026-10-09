<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

/** A JSON-RPC protocol error; the code is the JSON-RPC error code. */
final class McpError extends \RuntimeException {

    public function __construct(int $code, string $message) {
        parent::__construct($message, $code);
    }
}
