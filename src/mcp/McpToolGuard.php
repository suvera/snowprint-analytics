<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\suvera\snowprint\infra\Metrics;
use dev\winterframework\mcp\exception\McpToolDeniedException;
use dev\winterframework\mcp\McpToolContext;
use dev\winterframework\mcp\McpToolDefinition;
use dev\winterframework\mcp\McpToolInterceptor;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use Throwable;
use WeakMap;

/**
 * Per-tool rules for MCP calls: read-only API keys may not call write tools,
 * and every call is audit-logged (tool, site, key id, duration,
 * never the arguments) and counted in snowprint_mcp_tool_calls_total.
 */
#[Component]
class McpToolGuard implements McpToolInterceptor {
    use Wlf4p;

    #[Autowired]
    private McpCallers $callers;

    #[Autowired]
    private Metrics $metrics;

    /** Start time per call; keyed by the call's own context object, so coroutines never share an entry. */
    private ?WeakMap $started = null;

    public function isVisible(McpToolDefinition $tool, HttpRequest $request): bool {
        return true;
    }

    public function beforeCall(McpToolDefinition $tool, McpToolContext $ctx): void {
        $this->started ??= new WeakMap();
        $this->started[$ctx] = hrtime(true);
        if (!$tool->readOnly && !$this->callers->key($ctx->getRequest())->canWrite) {
            throw new McpToolDeniedException('read-only API key');
        }
    }

    public function afterCall(McpToolDefinition $tool, McpToolContext $ctx, ?Throwable $error): void {
        $started = $this->started[$ctx] ?? null;
        unset($this->started[$ctx]);
        $site = $ctx->getArgument('site');
        $this->metrics->mcpCall($tool->name, $ctx->isOk());
        self::logInfo('MCP tool call', [
            'tool' => $tool->name,
            'site' => is_string($site) ? mb_substr($site, 0, 255) : null,
            'key_id' => $this->callers->find($ctx->getRequest())?->id,
            'ms' => $started === null ? null : round((hrtime(true) - $started) / 1e6, 1),
            'ok' => $ctx->isOk(),
        ]);
    }
}
