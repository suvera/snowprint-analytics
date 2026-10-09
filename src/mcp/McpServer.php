<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\suvera\snowprint\infra\Metrics;
use dev\suvera\snowprint\site\ApiKey;
use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\SiteService;
use dev\suvera\snowprint\query\Filters;
use dev\suvera\snowprint\query\Period;
use dev\suvera\snowprint\query\StatsQuery;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\util\log\Wlf4p;

/**
 * Model Context Protocol message handling (JSON-RPC 2.0, PRD §7). Stateless:
 * no MCP session ids, every request carries its API key.
 */
#[Service]
class McpServer {
    use Wlf4p;

    public const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];
    public const LATEST_VERSION = '2025-06-18';

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    private const INSTRUCTIONS = 'Snowprint is privacy-first web analytics. Start with list_sites. Visitors are '
        . 'anonymous and counted per day (no cookies). Periods are interpreted in each site\'s timezone. To explain a '
        . 'change, compare periods with get_overview, locate the day with find_anomalies or get_timeseries, then use '
        . 'get_breakdown (source, page, country, ...) on that day to find what changed.';

    private const PROMPTS = [
        'weekly_report' => [
            'description' => 'Summarise the last 7 days against the week before.',
            'text' => 'Write a short weekly traffic report for {site}: compare the last 7 days with the previous 7 '
                . '(get_overview), name the top sources and pages (get_breakdown), goal conversions (get_goals) and '
                . 'any unusual days (find_anomalies). End with three concrete suggestions.',
        ],
        'explain_spike' => [
            'description' => 'Find out what caused a spike or drop in traffic.',
            'text' => 'Traffic for {site} changed unusually around {date}. Use find_anomalies and get_timeseries to '
                . 'confirm the day, then compare get_breakdown by source, referrer, page, utm_campaign and country for '
                . 'that day against the days before. Explain the most likely cause with numbers.',
        ],
        'seo_opportunities' => [
            'description' => 'Find pages that attract visitors but lose them.',
            'text' => 'For {site} over the last 30 days, find pages with many entries (get_breakdown entry_page) and '
                . 'check their bounce rate and visit duration with get_overview filtered by page. List the five pages '
                . 'where better content or links would keep the most visitors, with the evidence.',
        ],
        'campaign_review' => [
            'description' => 'Review UTM campaign performance.',
            'text' => 'Review campaigns for {site} over {period}: break down by utm_campaign, utm_source and '
                . 'utm_medium, compare visitors, bounce rate and goal conversions (get_goals filtered by '
                . 'utm_campaign), and say which campaigns to keep, fix or stop.',
        ],
    ];

    #[Autowired]
    private McpToolRegistry $tools;

    #[Autowired]
    private SiteService $sites;

    #[Autowired]
    private StatsQuery $stats;

    #[Autowired]
    private ApplicationContext $ctx;

    #[Autowired]
    private Metrics $metrics;

    /**
     * Handles one JSON-RPC message. Returns the response, or null for
     * notifications (no id).
     * @return array<string, mixed>|null
     */
    public function handle(mixed $message, ApiKey $key): ?array {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            return self::error(is_array($message) ? ($message['id'] ?? null) : null, self::INVALID_REQUEST, 'invalid JSON-RPC 2.0 request');
        }
        $isNotification = !array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($isNotification) {
            return null; // notifications/initialized, notifications/cancelled, ...: nothing to do
        }
        try {
            $result = match ($message['method']) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->tools->describe()],
                'tools/call' => $this->callTool($params, $key),
                'resources/list' => ['resources' => $this->resources($key)],
                'resources/templates/list' => ['resourceTemplates' => self::resourceTemplates()],
                'resources/read' => $this->readResource($params, $key),
                'prompts/list' => ['prompts' => self::prompts()],
                'prompts/get' => $this->getPrompt($params),
                default => throw new McpError(self::METHOD_NOT_FOUND, 'method not found: ' . $message['method']),
            };
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (McpError $e) {
            return self::error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            self::logException($e, 'MCP request failed. ');
            return self::error($id, self::INTERNAL_ERROR, 'internal error');
        }
    }

    public static function error(mixed $id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function initialize(array $params): array {
        $requested = $params['protocolVersion'] ?? null;
        return [
            'protocolVersion' => in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::LATEST_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['listChanged' => false, 'subscribe' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'snowprint',
                'title' => 'Snowprint Analytics',
                'version' => $this->ctx->getApplicationVersion(),
            ],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    private function callTool(array $params, ApiKey $key): array {
        $name = $params['name'] ?? null;
        $entry = is_string($name) ? $this->tools->find($name) : null;
        if ($entry === null) {
            throw new McpError(self::INVALID_PARAMS, 'unknown tool: ' . (is_string($name) ? $name : '(missing)'));
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new McpError(self::INVALID_PARAMS, 'arguments must be an object');
        }
        if (!$entry['tool']->readOnly && !$key->canWrite) {
            return self::toolError('this API key is read-only');
        }

        $started = hrtime(true);
        $ok = true;
        try {
            $result = $this->tools->invoke($entry, new ToolArgs($arguments), $key);
            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
                'structuredContent' => $result,
                'isError' => false,
            ];
        } catch (ToolError|InvalidInput $e) {
            $ok = false;
            return self::toolError($e->getMessage());
        } finally {
            $this->metrics->mcpCall((string) $name, $ok);
            // Audit log (PRD §7.2): never the arguments, only what was called and by which key.
            self::logInfo('MCP tool call', [
                'tool' => $name,
                'site' => is_string($arguments['site'] ?? null) ? mb_substr($arguments['site'], 0, 255) : null,
                'key_id' => $key->id,
                'ms' => round((hrtime(true) - $started) / 1e6, 1),
                'ok' => $ok,
            ]);
        }
    }

    private static function toolError(string $message): array {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    /** @return list<array<string, string>> two summary resources per readable site */
    private function resources(ApiKey $key): array {
        $out = [];
        foreach ($this->sites->all() as $site) {
            if (!$key->canRead($site['id'])) {
                continue;
            }
            foreach (['today' => 'today', 'last-7-days' => 'the last 7 days'] as $slug => $label) {
                $out[] = [
                    'uri' => "site://{$site['domain']}/summary/$slug",
                    'name' => "{$site['domain']} summary $slug",
                    'title' => "{$site['domain']}: $label",
                    'description' => "Traffic overview of {$site['domain']} for $label.",
                    'mimeType' => 'application/json',
                ];
            }
        }
        return $out;
    }

    private static function resourceTemplates(): array {
        return [[
            'uriTemplate' => 'site://{domain}/summary/{range}',
            'name' => 'site summary',
            'title' => 'Site traffic summary',
            'description' => 'Overview for a site; range is "today" or "last-7-days".',
            'mimeType' => 'application/json',
        ]];
    }

    private function readResource(array $params, ApiKey $key): array {
        $uri = (string) ($params['uri'] ?? '');
        if (!preg_match('#^site://([^/]+)/summary/(today|last-7-days)$#', $uri, $m)) {
            throw new McpError(self::INVALID_PARAMS, 'unknown resource: ' . $uri);
        }
        $site = $this->sites->findByDomain($m[1]);
        if ($site === null || !$key->canRead($site['id'])) {
            throw new McpError(self::INVALID_PARAMS, 'unknown resource: ' . $uri);
        }
        $period = Period::parse($m[2] === 'today' ? 'today' : '7d', $site['timezone']);
        $summary = ['site' => $site['domain'], 'period' => $period->describe()]
            + $this->stats->overviewWithComparison($site['id'], $period, Filters::none());
        return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json',
            'text' => json_encode($summary, JSON_UNESCAPED_SLASHES)]]];
    }

    private static function prompts(): array {
        $out = [];
        foreach (self::PROMPTS as $name => $prompt) {
            preg_match_all('/\{(\w+)\}/', $prompt['text'], $m);
            $out[] = [
                'name' => $name,
                'description' => $prompt['description'],
                'arguments' => array_map(static fn(string $arg) => [
                    'name' => $arg,
                    'description' => match ($arg) {
                        'site' => 'Site domain', 'date' => 'Day of the change (YYYY-MM-DD)',
                        'period' => 'Period, e.g. 30d', default => $arg,
                    },
                    'required' => $arg === 'site',
                ], array_values(array_unique($m[1]))),
            ];
        }
        return $out;
    }

    private function getPrompt(array $params): array {
        $prompt = self::PROMPTS[$params['name'] ?? ''] ?? null;
        if ($prompt === null) {
            throw new McpError(self::INVALID_PARAMS, 'unknown prompt');
        }
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (!is_string($args['site'] ?? null) || $args['site'] === '') {
            throw new McpError(self::INVALID_PARAMS, 'argument "site" is required');
        }
        $defaults = ['date' => 'the recent change', 'period' => 'the last 30 days'];
        $text = preg_replace_callback('/\{(\w+)\}/', static fn($m) => is_scalar($args[$m[1]] ?? null)
            ? (string) $args[$m[1]] : ($defaults[$m[1]] ?? $m[0]), $prompt['text']);
        return [
            'description' => $prompt['description'],
            'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]],
        ];
    }
}
