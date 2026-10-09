<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\suvera\snowprint\infra\PublicUrl;
use dev\suvera\snowprint\site\InviteService;
use dev\suvera\snowprint\site\UserService;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PostMapping;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;

/**
 * Dashboard sign-in (SP-017). The first visitor of a fresh install creates
 * the admin account (setup); everyone else signs in.
 */
#[RestController]
class AuthController {
    use UiApi;

    #[Autowired]
    private UserService $users;

    #[Autowired]
    private UiSessions $sessions;

    #[Autowired]
    private InviteService $invites;

    #[Autowired]
    private PublicUrl $publicUrl;

    #[Autowired]
    private ApplicationContext $ctx;

    #[GetMapping(path: '/api/ui/session')]
    public function session(HttpRequest $request): ResponseEntity {
        $user = $this->sessions->user($this->sessions->open($request));
        return ResponseEntity::ok()->withJson([
            'setup_required' => $user === null && $this->users->needsSetup(),
            'user' => $user?->toArray(),
            // Base URL for tracking snippets; '' means the dashboard's own origin.
            'public_url' => $this->publicUrl->get(),
            // GeoIP data credit to show next to locations ('' when none needed).
            'geo_attribution' => [
                'text' => trim($this->ctx->getPropertyStr('snowprint.geoip.attribution', '')),
                'url' => trim($this->ctx->getPropertyStr('snowprint.geoip.attributionUrl', '')),
            ],
        ]);
    }

    /** Body: {"email": "...", "name": "...", "password": "..."}; only while no user exists. */
    #[PostMapping(path: '/api/ui/setup')]
    public function setup(HttpRequest $request): ResponseEntity {
        return self::handle(function () use ($request) {
            self::requireUiHeader($request);
            $body = self::body($request);
            $user = $this->users->createFirstAdmin((string) ($body['email'] ?? ''), (string) ($body['name'] ?? ''),
                (string) ($body['password'] ?? ''));
            $session = $this->sessions->open($request);
            $this->sessions->signIn($session, $user);
            return $this->sessions->commit($session, ResponseEntity::ok()->withJson(['user' => $user->toArray()]));
        });
    }

    /** Body: {"email": "...", "password": "..."} */
    #[PostMapping(path: '/api/ui/login')]
    public function login(HttpRequest $request): ResponseEntity {
        return self::handle(function () use ($request) {
            self::requireUiHeader($request);
            $body = self::body($request);
            $user = $this->users->authenticate((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
            if ($user === null) {
                throw new UiError(HttpStatus::$UNAUTHORIZED, 'wrong email or password');
            }
            $session = $this->sessions->open($request);
            $this->sessions->signIn($session, $user);
            return $this->sessions->commit($session, ResponseEntity::ok()->withJson(['user' => $user->toArray()]));
        });
    }

    /**
     * Body: {"token": "..."}. The email an open invite is for. The token
     * travels in bodies only, never in URLs, so request logs never hold it.
     */
    #[PostMapping(path: '/api/ui/invite')]
    public function invite(HttpRequest $request): ResponseEntity {
        return self::handle(function () use ($request) {
            self::requireUiHeader($request);
            $invite = $this->invites->find((string) (self::body($request)['token'] ?? ''));
            if ($invite === null) {
                throw new UiError(HttpStatus::$NOT_FOUND, 'this invite link is invalid, used or expired');
            }
            return ResponseEntity::ok()->withJson(['invite' => $invite]);
        });
    }

    /** Body: {"token": "...", "name": "...", "password": "..."}; creates the account and signs in. */
    #[PostMapping(path: '/api/ui/invite/accept')]
    public function acceptInvite(HttpRequest $request): ResponseEntity {
        return self::handle(function () use ($request) {
            self::requireUiHeader($request);
            $body = self::body($request);
            $user = $this->invites->accept((string) ($body['token'] ?? ''), (string) ($body['name'] ?? ''),
                (string) ($body['password'] ?? ''));
            $session = $this->sessions->open($request);
            $this->sessions->signIn($session, $user);
            return $this->sessions->commit($session, ResponseEntity::ok()->withJson(['user' => $user->toArray()]));
        });
    }

    #[PostMapping(path: '/api/ui/logout')]
    public function logout(HttpRequest $request): ResponseEntity {
        return self::handle(function () use ($request) {
            self::requireUiHeader($request);
            $session = $this->sessions->open($request);
            $this->sessions->signOut($session);
            return $this->sessions->commit($session, ResponseEntity::ok()->withJson(['signed_out' => true]));
        });
    }
}
