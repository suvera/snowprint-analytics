<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\suvera\snowprint\infra\SessionStoreConfig;
use dev\suvera\snowprint\site\User;
use dev\suvera\snowprint\site\UserService;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\Value;
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
    /** Separate session for visitors of password-protected share links. */
    public const SHARE_COOKIE = 'snowprint_share';
    private const USER_ID = 'uid';
    private const UNLOCKED = 'shares';

    #[Autowired]
    private SessionManager $manager;

    #[Autowired]
    private \SessionHandlerInterface $store;

    #[Autowired]
    private UserService $users;

    /** Mark the cookies Secure (SNOWPRINT_SECURE_COOKIES=true behind HTTPS). */
    #[Value('${snowprint.ui.secureCookies}', false)]
    private bool $secureCookies = false;

    private ?SessionOptions $options = null;
    private ?SessionOptions $shareOptions = null;

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

    public function openShare(HttpRequest $request): RequestSession {
        return $this->manager->open($request, $this->store, $this->shareOptions());
    }

    public function isUnlocked(RequestSession $session, int $linkId): bool {
        return in_array($linkId, (array) $session->get(self::UNLOCKED), true);
    }

    /** Remembers a share link whose password this browser entered. */
    public function unlock(RequestSession $session, int $linkId): void {
        $ids = array_values(array_filter((array) $session->get(self::UNLOCKED), 'is_int'));
        $session->regenerateId(); // a privilege change, like signing in
        $ids[] = $linkId;
        $session->set(self::UNLOCKED, array_slice(array_values(array_unique($ids)), -20));
    }

    public function commitShare(RequestSession $session, ResponseEntity $response): ResponseEntity {
        $this->manager->commit($session, $response, $this->store, $this->shareOptions());
        return $response;
    }

    private function shareOptions(): SessionOptions {
        return $this->shareOptions ??= new SessionOptions(
            name: self::SHARE_COOKIE,
            expirySecs: SessionStoreConfig::TTL_SECONDS,
            secure: $this->options()->secure,
            httponly: true,
            samesite: 'Lax',
        );
    }

    private function options(): SessionOptions {
        return $this->options ??= new SessionOptions(
            name: self::COOKIE,
            expirySecs: SessionStoreConfig::TTL_SECONDS,
            secure: $this->secureCookies,
            httponly: true,
            samesite: 'Lax',
        );
    }
}
