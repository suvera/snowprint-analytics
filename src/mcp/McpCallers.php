<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\suvera\snowprint\site\ApiKey;
use dev\suvera\snowprint\site\ApiKeyService;
use dev\winterframework\mcp\exception\McpToolDeniedException;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\web\http\HttpRequest;

/**
 * The API key behind an MCP request ("Authorization: Bearer <key>").
 * McpAuthInterceptor has already refused requests without a valid key;
 * tools, resources and prompts look it up again, which hits the 30 s
 * #[Cacheable] in ApiKeyService.
 */
#[Component]
class McpCallers {

    #[Autowired]
    private ApiKeyService $keys;

    public function find(HttpRequest $request): ?ApiKey {
        $header = $request->getFirstHeader('Authorization') ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }
        return $this->keys->authenticate($m[1]);
    }

    public function key(HttpRequest $request): ApiKey {
        return $this->find($request) ?? throw new McpToolDeniedException('no valid API key');
    }
}
