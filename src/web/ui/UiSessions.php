<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\suvera\snowprint\infra\SessionStoreConfig;
use dev\suvera\snowprint\ingest\Tracker;
use dev\suvera\snowprint\site\User;
use dev\suvera\snowprint\site\UserService;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\session\RequestSession;
use dev\winterframework\web\session\SessionManager;
use dev\winterframework\web\session\SessionOptions;

/**
 * Dashboard login sessions on Winter Boot's SessionManager. The cookie is
 * HttpOnly and SameSite=Lax; set SNOWPRINT_SECURE_COOKIES=true behind HTTPS.
 */
#[Component]
class UiSessions {

    public const COOKIE = 'snowprint_session';
    private const USER_ID = 'uid';

    #[Autowired]
    private SessionManager $manager;

    #[Autowired]
    private \SessionHandlerInterface $store;

    #[Autowired]
    private UserService $users;

    #[Autowired]
    private ApplicationContext $ctx;

    private ?SessionOptions $options = null;

    public function open(HttpRequest $request): RequestSession {
        return $this->manager->open($request, $this->store, $this->options());
    }

    public function user(RequestSession $session): ?User {
        $id = $session->get(self::USER_ID);
        return is_int($id) ? $this->users->find($id) : null;
    }

    public function signIn(RequestSession $session, User $user): void {
        $session->regenerateId(); // never keep a pre-login id (session fixation)
        $session->set(self::USER_ID, $user->id);
        $session->setUsername($user->email);
    }

    public function signOut(RequestSession $session): void {
        $session->destroy();
    }

    /** Persists the session and sets (or clears) its cookie on the response. */
    public function commit(RequestSession $session, ResponseEntity $response): ResponseEntity {
        $this->manager->commit($session, $response, $this->store, $this->options());
        return $response;
    }

    private function options(): SessionOptions {
        return $this->options ??= new SessionOptions(
            name: self::COOKIE,
            expirySecs: SessionStoreConfig::TTL_SECONDS,
            secure: Tracker::truthy($this->ctx->getPropertyStr('snowprint.ui.secureCookies', 'false')),
            httponly: true,
            samesite: 'Lax',
        );
    }
}
