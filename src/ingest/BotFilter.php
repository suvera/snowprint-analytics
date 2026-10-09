<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

/**
 * Cheap first-pass bot detection on the user agent (PRD §6.1). Browsers that
 * run snow.js all send a "Mozilla/5.0"-style agent; crawlers, HTTP libraries,
 * headless browsers and uptime monitors are dropped.
 */
final class BotFilter {

    private const BOT_PATTERN = '/bot\b|bot[\/;_-]|crawl|spider|slurp|archiver|scrape|fetcher|'
        . 'headless|phantomjs|selenium|puppeteer|playwright|webdriver|lighthouse|pagespeed|gtmetrix|'
        . 'pingdom|uptime|monitor|statuscake|site24x7|newrelic|datadog|'
        . 'facebookexternalhit|embedly|preview|whatsapp|telegram|discord|skype|slack|'
        . 'curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|undici|httpclient|libwww|'
        . 'postman|insomnia|http_request|guzzle|ruby|perl|php\//i';

    public static function isBot(string $userAgent): bool {
        $ua = trim($userAgent);
        if (strlen($ua) < 20 || !str_starts_with($ua, 'Mozilla/')) {
            return true;
        }
        return preg_match(self::BOT_PATTERN, $ua) === 1;
    }
}
