<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;

/**
 * Collects #[McpTool] methods from the tool provider beans in PROVIDERS.
 */
#[Component]
class McpToolRegistry {

    /** Beans whose #[McpTool] methods are exposed. */
    public const PROVIDERS = [AnalyticsTools::class];

    #[Autowired]
    private ApplicationContext $ctx;

    /** @var array<string, array{tool: McpTool, bean: class-string, method: string}>|null */
    private ?array $tools = null;

    /** @return array<string, array{tool: McpTool, bean: class-string, method: string}> */
    public function all(): array {
        if ($this->tools === null) {
            $this->tools = [];
            foreach (self::PROVIDERS as $class) {
                foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    foreach ($method->getAttributes(McpTool::class) as $attribute) {
                        $tool = $attribute->newInstance();
                        $this->tools[$tool->name] = ['tool' => $tool, 'bean' => $class, 'method' => $method->getName()];
                    }
                }
            }
        }
        return $this->tools;
    }

    /** @return list<array<string, mixed>> tools/list entries */
    public function describe(): array {
        return array_values(array_map(static fn(array $t) => [
            'name' => $t['tool']->name,
            'title' => $t['tool']->title,
            'description' => $t['tool']->description,
            'inputSchema' => $t['tool']->inputSchema,
            'annotations' => ['title' => $t['tool']->title, 'readOnlyHint' => $t['tool']->readOnly, 'openWorldHint' => false],
        ], $this->all()));
    }

    public function find(string $name): ?array {
        return $this->all()[$name] ?? null;
    }

    public function invoke(array $entry, ToolArgs $args, \dev\suvera\snowprint\site\ApiKey $key): array {
        $bean = $this->ctx->beanByClass($entry['bean']);
        return $bean->{$entry['method']}($args, $key);
    }
}
