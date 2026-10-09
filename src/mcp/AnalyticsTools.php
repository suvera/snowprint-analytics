<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\suvera\snowprint\query\Dimensions;
use dev\suvera\snowprint\query\Filters;
use dev\suvera\snowprint\query\Period;
use dev\suvera\snowprint\query\StatsQuery;
use dev\suvera\snowprint\site\ApiKey;
use dev\suvera\snowprint\site\GoalService;
use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\SiteService;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;

/**
 * The read-only analytics tools (PRD §7.3, P0). Every tool works on one site
 * the API key may read; other sites are reported as not found.
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

    #[Autowired]
    private StatsQuery $stats;

    #[Autowired]
    private SiteService $sites;

    #[Autowired]
    private GoalService $goals;

    #[McpTool(
        name: 'list_sites',
        title: 'List sites',
        description: 'Sites this API key can read, with their timezones.',
    )]
    public function listSites(ToolArgs $args, ApiKey $key): array {
        $sites = array_values(array_filter($this->sites->all(), static fn(array $s) => $key->canRead($s['id'])));
        return ['sites' => array_map(static fn(array $s) => ['domain' => $s['domain'], 'timezone' => $s['timezone']], $sites)];
    }

    #[McpTool(
        name: 'get_overview',
        title: 'Traffic overview',
        description: 'Visitors, visits, pageviews, custom events, views per visit, bounce rate (%) and average visit '
            . 'duration (seconds) for a period, with the previous period of equal length and % change when compare is true. '
            . 'Visitors are counted per day (no cookies), so a visitor returning on another day counts again.',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => [
            'site' => self::SITE, 'period' => self::PERIOD + ['default' => '7d'],
            'compare' => ['type' => 'boolean', 'default' => true, 'description' => 'Include the previous period and % change.'],
            'filters' => self::FILTERS,
        ]],
    )]
    public function overview(ToolArgs $args, ApiKey $key): array {
        $site = $this->site($args, $key);
        $period = $this->period($args, $site, '7d');
        $filters = $this->filters($args);
        $result = $args->bool('compare', true)
            ? $this->stats->overviewWithComparison($site['id'], $period, $filters)
            : ['current' => $this->stats->overview($site['id'], $period, $filters)];
        return ['site' => $site['domain'], 'period' => $period->describe(), 'filters' => (object) $filters->filters] + $result;
    }

    #[McpTool(
        name: 'get_timeseries',
        title: 'Metric over time',
        description: 'One metric over a period in hourly, daily or monthly buckets (site timezone; empty buckets are 0). '
            . 'Use it to spot spikes and drops.',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => [
            'site' => self::SITE,
            'metric' => ['type' => 'string', 'enum' => StatsQuery::METRICS, 'default' => 'visitors'],
            'period' => self::PERIOD + ['default' => '30d'],
            'interval' => ['type' => 'string', 'enum' => StatsQuery::INTERVALS,
                'description' => 'Default: hour for up to 2 days, month beyond 90 days, else day.'],
            'filters' => self::FILTERS,
        ]],
    )]
    public function timeseries(ToolArgs $args, ApiKey $key): array {
        $site = $this->site($args, $key);
        $period = $this->period($args, $site, '30d');
        $metric = $args->string('metric', 'visitors');
        $series = $this->stats->timeseries($site['id'], $period, $this->filters($args), $metric, $args->optionalString('interval'));
        return ['site' => $site['domain'], 'metric' => $metric, 'period' => $period->describe(), 'series' => $series];
    }

    #[McpTool(
        name: 'get_breakdown',
        title: 'Top values of a dimension',
        description: 'Top values of one dimension (pages, entry/exit pages, sources, referrers, UTM tags, countries, '
            . 'regions, cities, browsers, operating systems, devices, custom events) with visitors, visits, pageviews '
            . 'and events, sorted by visitors.',
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
    )]
    public function breakdown(ToolArgs $args, ApiKey $key): array {
        $site = $this->site($args, $key);
        $period = $this->period($args, $site, '7d');
        $dimension = $args->string('dimension');
        $rows = $this->stats->breakdown($site['id'], $period, $this->filters($args), $dimension,
            $args->int('limit', 10, 1, StatsQuery::MAX_LIMIT));
        return ['site' => $site['domain'], 'dimension' => $dimension, 'period' => $period->describe(), 'rows' => $rows];
    }

    #[McpTool(
        name: 'get_realtime',
        title: 'Visitors right now',
        description: 'Visitors in the last 5 minutes and the pages they are on.',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => ['site' => self::SITE]],
    )]
    public function realtime(ToolArgs $args, ApiKey $key): array {
        $site = $this->site($args, $key);
        return ['site' => $site['domain']] + $this->stats->realtime($site['id']);
    }

    #[McpTool(
        name: 'get_goals',
        title: 'Goal conversions',
        description: 'Configured conversion goals with converting visitors, completions and conversion rate (% of all '
            . 'visitors in the period).',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => [
            'site' => self::SITE, 'period' => self::PERIOD + ['default' => '30d'], 'filters' => self::FILTERS,
        ]],
    )]
    public function goals(ToolArgs $args, ApiKey $key): array {
        $site = $this->site($args, $key);
        $period = $this->period($args, $site, '30d');
        return ['site' => $site['domain'], 'period' => $period->describe(),
            'goals' => $this->stats->goals($site['id'], $period, $this->filters($args))];
    }

    #[McpTool(
        name: 'find_anomalies',
        title: 'Unusual days',
        description: 'Days in the period whose visitors deviate from the mean of the preceding 28 days by at least '
            . '"sigma" standard deviations (spike or drop). Follow up with get_breakdown on that date to find the cause.',
        inputSchema: ['type' => 'object', 'required' => ['site'], 'properties' => [
            'site' => self::SITE, 'period' => self::PERIOD + ['default' => '30d'],
            'sigma' => ['type' => 'number', 'minimum' => 1, 'maximum' => 5, 'default' => 2],
            'filters' => self::FILTERS,
        ]],
    )]
    public function anomalies(ToolArgs $args, ApiKey $key): array {
        $site = $this->site($args, $key);
        $period = $this->period($args, $site, '30d');
        $sigma = max(1.0, min(5.0, $args->float('sigma', 2.0)));
        return ['site' => $site['domain'], 'period' => $period->describe(), 'sigma' => $sigma,
            'anomalies' => $this->stats->anomalies($site['id'], $period, $this->filters($args), $sigma)];
    }

    /** @return array{id: int, domain: string, timezone: string} */
    public function site(ToolArgs $args, ApiKey $key): array {
        $domain = $args->string('site');
        $site = $this->sites->findByDomain($domain);
        if ($site === null || !$key->canRead($site['id'])) {
            throw new ToolError("site \"$domain\" not found or not accessible with this API key; call list_sites");
        }
        return $site;
    }

    private function period(ToolArgs $args, array $site, string $default): Period {
        return Period::parse($args->string('period', $default), $site['timezone']);
    }

    private function filters(ToolArgs $args): Filters {
        return Filters::of($args->object('filters'));
    }
}
