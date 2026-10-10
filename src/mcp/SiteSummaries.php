<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\suvera\snowprint\query\Filters;
use dev\suvera\snowprint\query\Period;
use dev\suvera\snowprint\query\StatsQuery;
use dev\suvera\snowprint\site\SiteService;
use dev\winterframework\mcp\exception\McpResourceNotFoundException;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\mcp\McpResource;
use dev\winterframework\web\http\HttpRequest;

/**
 * MCP resources: a traffic summary per site for today and the
 * last 7 days. resources/list shows the two summaries of every site the
 * caller's API key may read; other sites answer "Resource not found".
 */
#[Service]
class SiteSummaries {

    private const RANGES = ['today' => 'today', 'last-7-days' => 'the last 7 days'];

    #[Autowired]
    private SiteService $sites;

    #[Autowired]
    private StatsQuery $stats;

    #[Autowired]
    private McpCallers $callers;

    #[McpResource(
        uri: 'site://{domain}/summary/{range}',
        name: 'site_summary',
        title: 'Site traffic summary',
        description: 'Overview for a site; range is "today" or "last-7-days".',
        mimeType: 'application/json',
        listMethod: 'listSummaries',
    )]
    public function summary(string $domain, string $range, HttpRequest $request): array {
        $site = $this->sites->findByDomain($domain);
        if (!isset(self::RANGES[$range]) || $site === null || !$this->callers->key($request)->canRead($site['id'])) {
            throw new McpResourceNotFoundException('unknown resource');
        }
        $period = Period::parse($range === 'today' ? 'today' : '7d', $site['timezone']);
        return ['site' => $site['domain'], 'period' => $period->describe()]
            + $this->stats->overviewWithComparison($site['id'], $period, Filters::none());
    }

    /** @return list<array{uri: string, name: string, title: string, description: string, mimeType: string}> */
    public function listSummaries(HttpRequest $request): array {
        $key = $this->callers->key($request);
        $out = [];
        foreach ($this->sites->all() as $site) {
            if (!$key->canRead($site['id'])) {
                continue;
            }
            foreach (self::RANGES as $slug => $label) {
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
}
