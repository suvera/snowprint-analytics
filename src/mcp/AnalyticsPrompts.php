<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\mcp\McpPrompt;

/**
 * MCP prompts: ready-made analysis requests that clients offer as
 * slash commands. Each returns the text of one user message.
 */
#[Service]
class AnalyticsPrompts {

    /**
     * @param string $site Site domain
     */
    #[McpPrompt(description: 'Summarise the last 7 days against the week before.', name: 'weekly_report')]
    public function weeklyReport(string $site): string {
        return "Write a short weekly traffic report for $site: compare the last 7 days with the previous 7 "
            . '(get_overview), name the top sources and pages (get_breakdown), goal conversions (get_goals) and '
            . 'any unusual days (find_anomalies). End with three concrete suggestions.';
    }

    /**
     * @param string $site Site domain
     * @param string $date Day of the change (YYYY-MM-DD)
     */
    #[McpPrompt(description: 'Find out what caused a spike or drop in traffic.', name: 'explain_spike')]
    public function explainSpike(string $site, string $date = 'the recent change'): string {
        return "Traffic for $site changed unusually around $date. Use find_anomalies and get_timeseries to "
            . 'confirm the day, then compare get_breakdown by source, referrer, page, utm_campaign and country for '
            . 'that day against the days before. Explain the most likely cause with numbers.';
    }

    /**
     * @param string $site Site domain
     */
    #[McpPrompt(description: 'Find pages that attract visitors but lose them.', name: 'seo_opportunities')]
    public function seoOpportunities(string $site): string {
        return "For $site over the last 30 days, find pages with many entries (get_breakdown entry_page) and "
            . 'check their bounce rate and visit duration with get_overview filtered by page. List the five pages '
            . 'where better content or links would keep the most visitors, with the evidence.';
    }

    /**
     * @param string $site   Site domain
     * @param string $period Period, e.g. 30d
     */
    #[McpPrompt(description: 'Review UTM campaign performance.', name: 'campaign_review')]
    public function campaignReview(string $site, string $period = 'the last 30 days'): string {
        return "Review campaigns for $site over $period: break down by utm_campaign, utm_source and "
            . 'utm_medium, compare visitors, bounce rate and goal conversions (get_goals filtered by '
            . 'utm_campaign), and say which campaigns to keep, fix or stop.';
    }
}
