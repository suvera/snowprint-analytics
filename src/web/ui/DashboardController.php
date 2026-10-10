<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\suvera\snowprint\query\Filters;
use dev\suvera\snowprint\query\Period;
use dev\suvera\snowprint\query\RollupService;
use dev\suvera\snowprint\query\StatsQuery;
use dev\suvera\snowprint\site\GoalService;
use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\ShareLinkService;
use dev\suvera\snowprint\site\SiteService;
use dev\suvera\snowprint\site\User;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\DeleteMapping;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PatchMapping;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\stereotype\web\PostMapping;
use dev\winterframework\stereotype\web\RequestParam;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;

/**
 * JSON API behind the React dashboard (D10). Reports reuse StatsQuery, the
 * same engine as MCP. Query parameters: site, period, filters (JSON object).
 * Reports also answer share-link visitors (SP-046): with an
 * "X-Snowprint-Share: <token>" header the link picks the site, not "site".
 */
#[RestController]
class DashboardController {
    use UiApi;

    #[Autowired]
    private UiSessions $sessions;

    #[Autowired]
    private SiteService $sites;

    #[Autowired]
    private GoalService $goals;

    #[Autowired]
    private StatsQuery $stats;

    #[Autowired]
    private RollupService $rollups;

    #[Autowired]
    private ShareLinkService $shares;

    #[GetMapping(path: '/api/ui/sites')]
    public function sites(HttpRequest $request): ResponseEntity {
        $user = $this->user($request);
        $sites = [];
        foreach ($this->sites->all() as $site) {
            if ($user->canRead($site['id'])) {
                $sites[] = $site + ['can_manage' => $user->canManage($site['id']), 'has_data' => $this->sites->hasData($site['id'])];
            }
        }
        return ResponseEntity::ok()->withJson(['sites' => $sites, 'can_manage' => $user->isAdmin]);
    }

    /** Admins only. Body: {"domain": "example.com", "timezone": "Europe/Berlin"} */
    #[PostMapping(path: '/api/ui/sites')]
    public function createSite(HttpRequest $request): ResponseEntity {
        $user = $this->user($request);
        if (!$user->isAdmin) {
            throw new UiError(HttpStatus::$FORBIDDEN, 'only admins can add sites');
        }
        $body = self::body($request);
        $site = $this->sites->create((string) ($body['domain'] ?? ''), (string) ($body['timezone'] ?? 'UTC'));
        return ResponseEntity::ok()->withJson(['site' => $site]);
    }

    /**
     * Site admins. Body: {"timezone": "Europe/Berlin", "retention_days": 90}, both optional.
     * A new timezone rebuilds the rollups of the days whose raw events still exist.
     */
    #[PatchMapping(path: '/api/ui/sites/{domain}')]
    public function updateSite(HttpRequest $request, #[PathVariable] string $domain): ResponseEntity {
        $user = $this->user($request);
        $site = $this->site($user, $domain);
        if (!$user->canManage($site['id'])) {
            throw new UiError(HttpStatus::$FORBIDDEN, 'only site admins can change settings');
        }
        $body = self::body($request);
        $retention = $body['retention_days'] ?? null;
        if ($retention !== null && !is_int($retention)) {
            throw new InvalidInput('retention_days must be a whole number');
        }
        $updated = $this->sites->update($site['id'], isset($body['timezone']) ? (string) $body['timezone'] : null, $retention);
        if ($updated['timezone'] !== $site['timezone']) {
            $this->rollups->rebuildFromRawEvents($site['id'], $updated['timezone']);
        }
        return ResponseEntity::ok()->withJson(['site' => $updated + ['can_manage' => true]]);
    }

    /** Instance admins. Body: {"confirm": "<domain>"}. Deletes the site and all its data. */
    #[DeleteMapping(path: '/api/ui/sites/{domain}')]
    public function deleteSite(HttpRequest $request, #[PathVariable] string $domain): ResponseEntity {
        $user = $this->user($request);
        $site = $this->site($user, $domain);
        if (!$user->isAdmin) {
            throw new UiError(HttpStatus::$FORBIDDEN, 'only admins can delete sites');
        }
        if ((self::body($request)['confirm'] ?? null) !== $site['domain']) {
            throw new InvalidInput('type the domain to confirm');
        }
        $this->sites->delete($site['id']);
        return ResponseEntity::ok()->withJson(['deleted' => $site['domain']]);
    }

    /** Site admins. Body: {"kind": "pageview"|"event", "match": "/thanks*"|"signup", "name": "..."} */
    #[PostMapping(path: '/api/ui/sites/{domain}/goals')]
    public function createGoal(HttpRequest $request, #[PathVariable] string $domain): ResponseEntity {
        $user = $this->user($request);
        $site = $this->site($user, $domain);
        if (!$user->canManage($site['id'])) {
            throw new UiError(HttpStatus::$FORBIDDEN, 'only site admins can add goals');
        }
        $body = self::body($request);
        $goal = $this->goals->create($site['id'], (string) ($body['kind'] ?? ''), (string) ($body['match'] ?? ''),
            isset($body['name']) ? (string) $body['name'] : null);
        return ResponseEntity::ok()->withJson(['goal' => $goal]);
    }

    /** Site admins: the site's share links (never their tokens). */
    #[GetMapping(path: '/api/ui/sites/{domain}/shares')]
    public function listShares(HttpRequest $request, #[PathVariable] string $domain): ResponseEntity {
        $site = $this->managedSite($request, $domain);
        return ResponseEntity::ok()->withJson(['shares' => $this->shares->forSite($site['id'])]);
    }

    /** Site admins. Body: {"label": "...", "password": "..."}, both optional. Returns the token once. */
    #[PostMapping(path: '/api/ui/sites/{domain}/shares')]
    public function createShare(HttpRequest $request, #[PathVariable] string $domain): ResponseEntity {
        $site = $this->managedSite($request, $domain);
        $body = self::body($request);
        $share = $this->shares->create($site['id'], $this->user($request)->id, (string) ($body['label'] ?? ''),
            isset($body['password']) ? (string) $body['password'] : null);
        return ResponseEntity::ok()->withJson(['share' => $share]);
    }

    #[DeleteMapping(path: '/api/ui/sites/{domain}/shares/{id}')]
    public function deleteShare(HttpRequest $request, #[PathVariable] string $domain, #[PathVariable] int $id): ResponseEntity {
        $site = $this->managedSite($request, $domain);
        if (!$this->shares->delete($site['id'], $id)) {
            throw new UiError(HttpStatus::$NOT_FOUND, 'share link not found');
        }
        return ResponseEntity::ok()->withJson(['deleted' => $id]);
    }

    #[GetMapping(path: '/api/ui/stats/overview')]
    public function overview(HttpRequest $request): ResponseEntity {
        return $this->report($request, fn(array $site, Period $p, Filters $f) =>
            $this->stats->overviewWithComparison($site['id'], $p, $f));
    }

    /**
     * Extra parameters: metric (visitors|pageviews|visits|events), interval
     * (hour|day|month), compare=1 to add the previous period's series.
     */
    #[GetMapping(path: '/api/ui/stats/timeseries')]
    public function timeseries(
        HttpRequest $request,
        #[RequestParam(required: false, defaultValue: 'visitors', source: 'get')] string $metric,
        #[RequestParam(required: false, defaultValue: '', source: 'get')] string $interval,
        #[RequestParam(required: false, defaultValue: '', source: 'get')] string $compare,
    ): ResponseEntity {
        return $this->report($request, function (array $site, Period $p, Filters $f) use ($metric, $interval, $compare) {
            $interval = $interval ?: $p->defaultInterval();
            $result = ['series' => $this->stats->timeseries($site['id'], $p, $f, $metric, $interval)];
            if ($compare === '1') {
                $previous = $this->stats->timeseries($site['id'], $p->previous(), $f, $metric, $interval);
                // Same bucket count as the current period (months can differ in length).
                $result['previous'] = array_slice(array_pad($previous, count($result['series']), null), 0, count($result['series']));
                $result['previous'] = array_map(static fn($pt) => $pt ?? ['date' => '', 'value' => 0], $result['previous']);
            }
            return $result;
        });
    }

    /**
     * Extra parameters: dimension, limit (max 100), compare=1 to add each row's
     * visitors in the previous period and the % change.
     */
    #[GetMapping(path: '/api/ui/stats/breakdown')]
    public function breakdown(
        HttpRequest $request,
        #[RequestParam(required: false, defaultValue: '', source: 'get')] string $dimension,
        #[RequestParam(required: false, defaultValue: 10, source: 'get')] int $limit,
        #[RequestParam(required: false, defaultValue: '', source: 'get')] string $compare,
    ): ResponseEntity {
        return $this->report($request, function (array $site, Period $p, Filters $f) use ($dimension, $limit, $compare) {
            $rows = $this->stats->breakdown($site['id'], $p, $f, $dimension, $limit);
            if ($compare === '1' && $rows !== []) {
                $before = array_column(
                    $this->stats->breakdown($site['id'], $p->previous(), $f, $dimension, StatsQuery::MAX_LIMIT),
                    'visitors', 'value');
                foreach ($rows as &$row) {
                    $prev = $before[$row['value']] ?? null;
                    $row['previous_visitors'] = $prev;
                    $row['change'] = $prev ? round(100 * ($row['visitors'] - $prev) / $prev, 1) : null;
                }
                unset($row);
            }
            return ['dimension' => $dimension, 'rows' => $rows];
        });
    }

    #[GetMapping(path: '/api/ui/stats/goals')]
    public function goalStats(HttpRequest $request): ResponseEntity {
        return $this->report($request, fn(array $site, Period $p, Filters $f) =>
            ['goals' => $this->stats->goals($site['id'], $p, $f)]);
    }

    #[GetMapping(path: '/api/ui/stats/realtime')]
    public function realtime(HttpRequest $request): ResponseEntity {
        $site = $this->reportSite($request);
        return ResponseEntity::ok()->withJson($this->stats->realtime($site['id']));
    }

    /** @param \Closure(array, Period, Filters): array $query */
    private function report(HttpRequest $request, \Closure $query): ResponseEntity {
        $site = $this->reportSite($request);
        $period = Period::parse((string) ($request->getQueryParam('period') ?? '7d'), $site['timezone']);
        $raw = (string) $request->getQueryParam('filters');
        $filters = Filters::of($raw === '' ? null : self::decodeFilters($raw));
        return ResponseEntity::ok()->withJson(
            ['site' => $site['domain'], 'period' => $period->describe()] + $query($site, $period, $filters));
    }

    private static function decodeFilters(string $raw): array {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidInput('filters must be a JSON object');
        }
        return $decoded;
    }

    private function user(HttpRequest $request): User {
        $user = $this->sessions->user($this->sessions->open($request));
        if ($user === null) {
            throw new UiError(HttpStatus::$UNAUTHORIZED, 'sign in first');
        }
        return $user;
    }

    /**
     * The site a report is for: the share link's site when the request
     * carries X-Snowprint-Share, else the signed-in user's "site" parameter.
     * @return array{id: int, domain: string, timezone: string, retention_days: int}
     */
    private function reportSite(HttpRequest $request): array {
        $token = (string) $request->getFirstHeader('X-Snowprint-Share');
        if ($token === '') {
            return $this->site($this->user($request), (string) $request->getQueryParam('site'));
        }
        $link = $this->shares->resolve($token) ?? throw new UiError(HttpStatus::$NOT_FOUND, 'this share link does not exist');
        if ($link['password_hash'] !== null && !$this->sessions->isUnlocked($this->sessions->openShare($request), $link['id'])) {
            throw new UiError(HttpStatus::$UNAUTHORIZED, 'enter the password of this share link');
        }
        return $this->sites->find($link['site_id']) ?? throw new UiError(HttpStatus::$NOT_FOUND, 'site not found');
    }

    /** @return array{id: int, domain: string, timezone: string, retention_days: int} a site the user manages */
    private function managedSite(HttpRequest $request, string $domain): array {
        $user = $this->user($request);
        $site = $this->site($user, $domain);
        if (!$user->canManage($site['id'])) {
            throw new UiError(HttpStatus::$FORBIDDEN, 'only site admins can manage share links');
        }
        return $site;
    }

    /** @return array{id: int, domain: string, timezone: string, retention_days: int} */
    private function site(User $user, string $domain): array {
        $site = $this->sites->findByDomain($domain);
        if ($site === null || !$user->canRead($site['id'])) {
            throw new UiError(HttpStatus::$NOT_FOUND, 'site not found');
        }
        return $site;
    }
}
