<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use Attribute;

/**
 * Marks a method of an MCP tool provider as an MCP tool (PRD §7.3). The
 * method receives (ToolArgs $args, ApiKey $key) and returns a JSON object.
 * Upstream candidate: a Winter Boot stereotype with automatic discovery.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class McpTool {

    /** @param array<string, mixed> $inputSchema JSON Schema for the arguments */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly array $inputSchema = ['type' => 'object', 'properties' => new \stdClass()],
        public readonly bool $readOnly = true,
    ) {
    }
}
