<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\admin;

use dev\suvera\snowprint\query\RetentionService;
use dev\suvera\snowprint\query\RollupBuilder;
use dev\suvera\snowprint\site\ApiKeyService;
use dev\suvera\snowprint\site\GoalService;
use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\SiteService;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\DeleteMapping;
use dev\winterframework\stereotype\web\GetMapping;
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
