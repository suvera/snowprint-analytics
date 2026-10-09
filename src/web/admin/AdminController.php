<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\admin;

use dev\suvera\snowprint\infra\PublicUrl;
use dev\suvera\snowprint\query\RetentionService;
use dev\suvera\snowprint\query\RollupBuilder;
use dev\suvera\snowprint\query\RollupService;
use dev\suvera\snowprint\site\ApiKeyService;
use dev\suvera\snowprint\site\GoalService;
use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\ShareLinkService;
use dev\suvera\snowprint\site\SiteService;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\DeleteMapping;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PatchMapping;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\stereotype\web\PostMapping;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;

/**
 * Operator API behind bin/console.sh (guarded by OperatorInterceptor).
 */
#[RestController]
class AdminController {

    #[Autowired]
    private SiteService $sites;

    #[Autowired]
    private ApiKeyService $keys;

    #[Autowired]
    private GoalService $goals;

    #[Autowired]
    private RollupBuilder $rollups;

    #[Autowired]
    private RetentionService $retention;

    #[Autowired]
    private RollupService $rollupData;

    #[Autowired]
    private ShareLinkService $shares;

    #[Autowired]
    private PublicUrl $publicUrl;

    #[GetMapping(path: '/api/admin/sites')]
    public function listSites(): array {
        return ['sites' => $this->sites->all()];
    }

    #[PostMapping(path: '/api/admin/sites')]
    public function createSite(HttpRequest $request): ResponseEntity {
        return self::guard(function () use ($request) {
            $body = self::json($request);
            $site = $this->sites->create((string) ($body['domain'] ?? ''), (string) ($body['timezone'] ?? 'UTC'));
            return ResponseEntity::ok(['site' => $site]);
        });
    }

    /** Body: {"timezone": "Europe/Berlin"} and/or {"retention_days": 90} */
    #[PatchMapping(path: '/api/admin/sites/{domain}')]
    public function updateSite(HttpRequest $request, #[PathVariable] string $domain): ResponseEntity {
        return self::guard(function () use ($request, $domain) {
            $body = self::json($request);
            $site = $this->sites->findByDomain($domain) ?? throw new InvalidInput('unknown site: ' . $domain);
            $retention = isset($body['retention_days']) ? filter_var($body['retention_days'], FILTER_VALIDATE_INT) : null;
            if ($retention === false) {
                throw new InvalidInput('retention_days must be a whole number');
            }
            $updated = $this->sites->update($site['id'], isset($body['timezone']) ? (string) $body['timezone'] : null, $retention);
            if ($updated['timezone'] !== $site['timezone']) {
                $this->rollupData->rebuildFromRawEvents($site['id'], $updated['timezone']);
            }
            return ResponseEntity::ok(['site' => $updated]);
        });
    }

    /** Body: {"site": "example.com", "kind": "pageview"|"event", "match": "/thanks*"|"signup", "name": "..."} */
    #[PostMapping(path: '/api/admin/goals')]
    public function createGoal(HttpRequest $request): ResponseEntity {
        return self::guard(function () use ($request) {
            $body = self::json($request);
            $siteId = $this->sites->idsForDomains([(string) ($body['site'] ?? '')])[0];
            $goal = $this->goals->create($siteId, (string) ($body['kind'] ?? ''), (string) ($body['match'] ?? ''),
                isset($body['name']) ? (string) $body['name'] : null);
            return ResponseEntity::ok(['goal' => $goal]);
        });
    }

    #[GetMapping(path: '/api/admin/api-keys')]
    public function listKeys(): array {
        return ['api_keys' => $this->keys->all()];
    }

    /** Body: {"name": "...", "sites": ["example.com"] | "all", "write": false} */
    #[PostMapping(path: '/api/admin/api-keys')]
    public function createKey(HttpRequest $request): ResponseEntity {
        return self::guard(function () use ($request) {
            $body = self::json($request);
            $sites = $body['sites'] ?? null;
            if ($sites === 'all') {
                $siteIds = null;
            } elseif (is_array($sites) && $sites !== []) {
                $siteIds = $this->sites->idsForDomains(array_map('strval', $sites));
            } else {
                throw new InvalidInput('"sites" must be a list of domains or "all"');
            }
            $created = $this->keys->create((string) ($body['name'] ?? ''), $siteIds, ($body['write'] ?? false) === true);
            return ResponseEntity::ok([
                'api_key' => $created,
                'note' => 'Store this key now: it is not shown again.',
            ]);
        });
    }

    #[DeleteMapping(path: '/api/admin/api-keys/{id}')]
    public function revokeKey(#[PathVariable] int $id): ResponseEntity {
        return $this->keys->revoke($id)
            ? ResponseEntity::ok(['revoked' => $id])
            : ResponseEntity::notFound()->withJson(['error' => 'no active key with id ' . $id]);
    }

    #[GetMapping(path: '/api/admin/sites/{domain}/shares')]
    public function listShares(#[PathVariable] string $domain): ResponseEntity {
        return self::guard(function () use ($domain) {
            $site = $this->sites->findByDomain($domain) ?? throw new InvalidInput('unknown site: ' . $domain);
            return ResponseEntity::ok(['shares' => $this->shares->forSite($site['id'])]);
        });
    }

    /**
     * Body: {"label": "..."}. A share link without a password (set passwords in
     * the dashboard, not on a command line). The link is shown once.
     */
    #[PostMapping(path: '/api/admin/sites/{domain}/shares')]
    public function createShare(HttpRequest $request, #[PathVariable] string $domain): ResponseEntity {
        return self::guard(function () use ($request, $domain) {
            $site = $this->sites->findByDomain($domain) ?? throw new InvalidInput('unknown site: ' . $domain);
            $share = $this->shares->create($site['id'], null, (string) (self::json($request)['label'] ?? ''), null);
            $base = $this->publicUrl->get();
            return ResponseEntity::ok([
                'share' => $share + ['url' => $base === '' ? '' : $base . $share['path']],
                'note' => 'Anyone with this link can read the dashboard of ' . $site['domain'] . '. It is not shown again.',
            ]);
        });
    }

    #[DeleteMapping(path: '/api/admin/sites/{domain}/shares/{id}')]
    public function deleteShare(#[PathVariable] string $domain, #[PathVariable] int $id): ResponseEntity {
        return self::guard(function () use ($domain, $id) {
            $site = $this->sites->findByDomain($domain) ?? throw new InvalidInput('unknown site: ' . $domain);
            return $this->shares->delete($site['id'], $id)
                ? ResponseEntity::ok(['deleted' => $id])
                : ResponseEntity::notFound()->withJson(['error' => 'no share link with id ' . $id . ' on ' . $site['domain']]);
        });
    }

    /** Runs the rollup job now (it also runs every 10 minutes on worker pods). */
    #[PostMapping(path: '/api/admin/jobs/rollup')]
    public function runRollup(): array {
        return ['days_rolled_up' => $this->rollups->rollPending()];
    }

    /** Runs the retention sweep now (it also runs hourly on worker pods). */
    #[PostMapping(path: '/api/admin/jobs/retention')]
    public function runRetention(): array {
        return $this->retention->apply();
    }

    private static function json(HttpRequest $request): array {
        $body = json_decode($request->getRawBody(), true);
        if (!is_array($body)) {
            throw new InvalidInput('request body must be a JSON object');
        }
        return $body;
    }

    /** @param \Closure(): ResponseEntity $action */
    private static function guard(\Closure $action): ResponseEntity {
        try {
            return $action();
        } catch (InvalidInput $e) {
            return ResponseEntity::badRequest()->withJson(['error' => $e->getMessage()]);
        }
    }
}
