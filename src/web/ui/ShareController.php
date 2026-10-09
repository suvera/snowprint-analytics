<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\suvera\snowprint\site\ShareLinkService;
use dev\suvera\snowprint\site\SiteService;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\PostMapping;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;

/**
 * Opening a public share link (SP-046). The token travels in bodies and in
 * the X-Snowprint-Share header, never in URLs, so request logs never hold
 * it. The reports themselves are DashboardController's /api/ui/stats/*.
 */
#[RestController]
class ShareController {
    use UiApi;

    #[Autowired]
    private ShareLinkService $shares;

    #[Autowired]
    private SiteService $sites;

    #[Autowired]
    private UiSessions $sessions;

    /** Body: {"token": "..."}. The shared site and whether a password is still needed. */
    #[PostMapping(path: '/api/share/info')]
    public function info(HttpRequest $request): ResponseEntity {
        return self::handle(function () use ($request) {
            self::requireUiHeader($request);
            [$link, $site] = $this->link((string) (self::body($request)['token'] ?? ''));
            $locked = $link['password_hash'] !== null
                && !$this->sessions->isUnlocked($this->sessions->openShare($request), $link['id']);
            if (!$locked) {
                $this->shares->touch($link['id']);
            }
            return ResponseEntity::ok()->withJson([
                'site' => $locked ? null : ['domain' => $site['domain'], 'timezone' => $site['timezone'],
                    'has_data' => $this->sites->hasData($site['id'])],
                'label' => $link['label'],
                'password_required' => $locked,
            ]);
        });
    }

    /** Body: {"token": "...", "password": "..."}. Remembers the unlock in a cookie. */
    #[PostMapping(path: '/api/share/unlock')]
    public function unlock(HttpRequest $request): ResponseEntity {
        return self::handle(function () use ($request) {
            self::requireUiHeader($request);
            $body = self::body($request);
            [$link] = $this->link((string) ($body['token'] ?? ''));
            if (!$this->shares->checkPassword($link, (string) ($body['password'] ?? ''))) {
                throw new UiError(HttpStatus::$UNAUTHORIZED, 'wrong password');
            }
            $session = $this->sessions->openShare($request);
            $this->sessions->unlock($session, $link['id']);
            return $this->sessions->commitShare($session, ResponseEntity::ok()->withJson(['unlocked' => true]));
        });
    }

    /** @return array{0: array{id: int, site_id: int, label: string, password_hash: ?string}, 1: array} */
    private function link(string $token): array {
        $link = $this->shares->resolve($token);
        $site = $link === null ? null : $this->sites->find($link['site_id']);
        if ($site === null) {
            throw new UiError(HttpStatus::$NOT_FOUND, 'this share link does not exist or was deleted');
        }
        return [$link, $site];
    }
}
