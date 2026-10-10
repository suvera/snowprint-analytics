<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

use dev\suvera\snowprint\privacy\SaltService;
use dev\suvera\snowprint\privacy\VisitorHasher;
use dev\suvera\snowprint\site\SiteDirectory;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\stereotype\Value;

/**
 * Turns one tracker request into one buffered `events` row. The IP and user
 * agent are used to derive the visitor hash and are then discarded.
 */
#[Service]
class Tracker {

    public const ACCEPTED = 'accepted';
    public const UNKNOWN_SITE = 'unknown-site';
    public const DO_NOT_TRACK = 'dnt';
    public const BOT = 'bot';

    #[Autowired]
    private SiteDirectory $sites;

    #[Autowired]
    private SaltService $salts;

    #[Autowired]
    private EventBuffer $buffer;

    #[Autowired]
    private GeoLocator $geo;

    #[Value('${snowprint.ingest.respectDnt}', true)]
    private bool $respectDnt = true;

    public function track(TrackingPayload $payload, ClientInfo $client, ?\DateTimeImmutable $now = null): string {
        if ($client->doNotTrack && $this->respectDnt) {
            return self::DO_NOT_TRACK;
        }
        if (BotFilter::isBot($client->userAgent)) {
            return self::BOT;
        }
        $agent = UserAgentParser::parse($client->userAgent);
        if ($agent->bot) {
            return self::BOT;
        }
        $siteId = $this->sites->findSiteId($payload->domain);
        if ($siteId === null) {
            return self::UNKNOWN_SITE;
        }

        $page = PageUrl::parse($payload->url);
        $referrerHost = PageUrl::referrerHost($payload->referrer, $page->hostname);
        $location = $this->geo->locate($client->ip);
        $hash = VisitorHasher::hash($this->salts->currentSalt(), $siteId, $client->ip, $client->userAgent);

        $this->buffer->add([
            'site_id' => $siteId,
            'ts' => ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP'),
            'visitor_hash' => bin2hex($hash),
            'name' => $payload->name,
            'hostname' => $page->hostname,
            'path' => $page->path,
            'title' => $payload->title,
            'referrer_source' => ReferrerSource::name($referrerHost, $page->utm['utm_source'] ?? null),
            'referrer_host' => $referrerHost,
            'utm_source' => $page->utm['utm_source'] ?? null,
            'utm_medium' => $page->utm['utm_medium'] ?? null,
            'utm_campaign' => $page->utm['utm_campaign'] ?? null,
            'utm_term' => $page->utm['utm_term'] ?? null,
            'utm_content' => $page->utm['utm_content'] ?? null,
            'country' => $location['country'],
            'region' => $location['region'],
            'city' => $location['city'],
            'browser' => $agent->browser,
            'browser_version' => $agent->browserVersion,
            'os' => $agent->os,
            'os_version' => $agent->osVersion,
            'device' => $agent->device,
            'screen_w' => $payload->screenWidth,
            'screen_h' => $payload->screenHeight,
            'props' => $payload->props === [] ? null : json_encode($payload->props, JSON_UNESCAPED_UNICODE),
        ]);
        return self::ACCEPTED;
    }
}
