<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\suvera\snowprint\query\Filters;
use dev\suvera\snowprint\query\Period;
use dev\suvera\snowprint\query\StatsQuery;
use dev\suvera\snowprint\site\SiteService;
use dev\winterframework\mcp\exception\McpToolArgumentException;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\mcp\McpTool;
use dev\winterframework\web\http\HttpRequest;

/**
 * The read-only analytics tools, served by Winter Boot's MCP
 * endpoint. Every tool works on one site the API key may read; other sites
 * are reported as not found. Parameters are named like the inputSchema
 * properties (Winter Boot checks that at startup); $request is the agent's
 * /api/mcp request and is not a tool argument.
 */
#[Component]
class AnalyticsTools {

    private const SITE = ['type' => 'string', 'description' => 'Site domain, e.g. "example.com" (see list_sites).'];
    private const PERIOD = ['type' => 'string', 'description' => 'today, yesterday, 7d, 30d, 90d, month, last_month, 12mo, '
        . 'YYYY-MM-DD, or YYYY-MM-DD..YYYY-MM-DD (inclusive). Days are in the site\'s timezone.'];
    private const FILTERS = ['type' => 'object', 'description' => 'Optional exact-match filters, e.g. {"page": "/blog/*", '
        . '"country": "DE", "source": "Google"}. Keys: page, hostname, source, referrer, utm_source, utm_medium, '
        . 'utm_campaign, utm_term, utm_content, country, region, city, browser, os, device, event. '
        . 'page accepts * wildcards; source "Direct / None" means no referrer.',
        'additionalProperties' => ['type' => 'string']];
    /** Results are JSON objects; declaring that much keeps structuredContent in every result. */
    private const OBJECT = ['type' => 'object'];

    #[Autowired]
    private StatsQuery $stats;

    #[Autowired]
    private SiteService $sites;

    #[Autowired]
    private McpCallers $callers;

    #[McpTool(
        description: 'Sites this API key can read, with their timezones.',
        name: 'list_sites',
        title: 'List sites',
        outputSchema: self::OBJECT,
        readOnly: true,
        openWorld: false,
    )]
    public function listSites(HttpRequest $request): array {
        $key = $this->callers->key($request);
        $sites = array_values(array_filter($this->sites->all(), static fn(array $s) => $key->canRead($s['id'])));
        return ['sites' => array_map(static fn(array $s) => ['domain' => $s['domain'], 'timezone' => $s['timezone']], $sites)];
    }

    #[McpTool(
        description: 'Visitors, visits, pageviews, custom events, views per visit, bounce rate (%) and average visit '
            . 'duration (seconds) for a period, with the previous period of equal length and % change when compare is true. '
            . 'Visitors are counted per day (no cookies), so a visitor returning on another day counts again.',
        name: 'get_overview',
        title: 'Traffic overview',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => [
            'site' => self::SITE, 'period' => self::PERIOD + ['default' => '7d'],
            'compare' => ['type' => 'boolean', 'default' => true, 'description' => 'Include the previous period and % change.'],
            'filters' => self::FILTERS,
        ]],
        outputSchema: self::OBJECT,
        readOnly: true,
        openWorld: false,
    )]
    public function overview(
        HttpRequest $request,
        string $site,
        string $period = '7d',
        bool $compare = true,
        ?array $filters = null,
    ): array {
        $s = $this->site($request, $site);
        $p = self::period($period, $s);
        $f = Filters::of($filters);
        $result = $compare
            ? $this->stats->overviewWithComparison($s['id'], $p, $f)
            : ['current' => $this->stats->overview($s['id'], $p, $f)];
        return ['site' => $s['domain'], 'period' => $p->describe(), 'filters' => (object) $f->filters] + $result;
    }

    #[McpTool(
        description: 'One metric over a period in hourly, daily or monthly buckets (site timezone; empty buckets are 0). '
            . 'Use it to spot spikes and drops.',
        name: 'get_timeseries',
        title: 'Metric over time',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => [
            'site' => self::SITE,
            'metric' => ['type' => 'string', 'enum' => StatsQuery::METRICS, 'default' => 'visitors'],
            'period' => self::PERIOD + ['default' => '30d'],
            'interval' => ['type' => 'string', 'enum' => StatsQuery::INTERVALS,
                'description' => 'Default: hour for up to 2 days, month beyond 90 days, else day.'],
            'filters' => self::FILTERS,
        ]],
        outputSchema: self::OBJECT,
        readOnly: true,
        openWorld: false,
    )]
    public function timeseries(
        HttpRequest $request,
        string $site,
        string $metric = 'visitors',
        string $period = '30d',
        ?string $interval = null,
        ?array $filters = null,
    ): array {
        $s = $this->site($request, $site);
        $p = self::period($period, $s);
        $series = $this->stats->timeseries($s['id'], $p, Filters::of($filters), $metric, $interval);
        return ['site' => $s['domain'], 'metric' => $metric, 'period' => $p->describe(), 'series' => $series];
    }

    #[McpTool(
        description: 'Top values of one dimension (pages, entry/exit pages, sources, referrers, UTM tags, countries, '
            . 'regions, cities, browsers, operating systems, devices, custom events) with visitors, visits, pageviews '
            . 'and events, sorted by visitors.',
        name: 'get_breakdown',
        title: 'Top values of a dimension',
        inputSchema: ['type' => 'object', 'required' => ['site', 'dimension'], 'properties' => [
            'site' => self::SITE,
            'dimension' => ['type' => 'string', 'enum' => [
                'page', 'entry_page', 'exit_page', 'hostname', 'source', 'referrer', 'utm_source', 'utm_medium',
                'utm_campaign', 'utm_term', 'utm_content', 'country', 'region', 'city', 'browser', 'os', 'device', 'event',
            ]],
            'period' => self::PERIOD + ['default' => '7d'],
            'filters' => self::FILTERS,
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => StatsQuery::MAX_LIMIT, 'default' => 10],
        ]],
        outputSchema: self::OBJECT,
        readOnly: true,
        openWorld: false,
    )]
    public function breakdown(
        HttpRequest $request,
        string $site,
        string $dimension,
        string $period = '7d',
        ?array $filters = null,
        int $limit = 10,
    ): array {
        $s = $this->site($request, $site);
        $p = self::period($period, $s);
        $rows = $this->stats->breakdown($s['id'], $p, Filters::of($filters), $dimension, $limit);
        return ['site' => $s['domain'], 'dimension' => $dimension, 'period' => $p->describe(), 'rows' => $rows];
    }

    #[McpTool(
        description: 'Visitors in the last 5 minutes and the pages they are on.',
        name: 'get_realtime',
        title: 'Visitors right now',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => ['site' => self::SITE]],
        outputSchema: self::OBJECT,
        readOnly: true,
        openWorld: false,
    )]
    public function realtime(HttpRequest $request, string $site): array {
        $s = $this->site($request, $site);
        return ['site' => $s['domain']] + $this->stats->realtime($s['id']);
    }

    #[McpTool(
        description: 'Configured conversion goals with converting visitors, completions and conversion rate (% of all '
            . 'visitors in the period).',
        name: 'get_goals',
        title: 'Goal conversions',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => [
            'site' => self::SITE, 'period' => self::PERIOD + ['default' => '30d'], 'filters' => self::FILTERS,
        ]],
        outputSchema: self::OBJECT,
        readOnly: true,
        openWorld: false,
    )]
    public function goals(HttpRequest $request, string $site, string $period = '30d', ?array $filters = null): array {
        $s = $this->site($request, $site);
        $p = self::period($period, $s);
        return ['site' => $s['domain'], 'period' => $p->describe(),
            'goals' => $this->stats->goals($s['id'], $p, Filters::of($filters))];
    }

    #[McpTool(
        description: 'Days in the period whose visitors deviate from the mean of the preceding 28 days by at least '
            . '"sigma" standard deviations (spike or drop). Today is left out until it ends. '
            . 'Follow up with get_breakdown on that date to find the cause.',
        name: 'find_anomalies',
        title: 'Unusual days',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => [
            'site' => self::SITE, 'period' => self::PERIOD + ['default' => '30d'],
            'sigma' => ['type' => 'number', 'minimum' => 1, 'maximum' => 5, 'default' => 2],
            'filters' => self::FILTERS,
        ]],
        outputSchema: self::OBJECT,
        readOnly: true,
        openWorld: false,
    )]
    public function anomalies(
        HttpRequest $request,
        string $site,
        string $period = '30d',
        float $sigma = 2.0,
        ?array $filters = null,
    ): array {
        $s = $this->site($request, $site);
        $p = self::period($period, $s);
        return ['site' => $s['domain'], 'period' => $p->describe(), 'sigma' => $sigma,
            'anomalies' => $this->stats->anomalies($s['id'], $p, Filters::of($filters), $sigma)];
    }

    /** @return array{id: int, domain: string, timezone: string} a site the caller's API key may read */
    private function site(HttpRequest $request, string $domain): array {
        $domain = trim($domain);
        $site = $domain === '' ? null : $this->sites->findByDomain($domain);
        if ($site === null || !$this->callers->key($request)->canRead($site['id'])) {
            throw new McpToolArgumentException("site \"$domain\" not found or not accessible with this API key; call list_sites");
        }
        return $site;
    }

    private static function period(string $period, array $site): Period {
        return Period::parse(trim($period), $site['timezone']);
    }
}
