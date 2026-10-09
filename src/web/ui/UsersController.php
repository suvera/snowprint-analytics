<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web\ui;

use dev\suvera\snowprint\infra\mail\MailException;
use dev\suvera\snowprint\infra\mail\Mailer;
use dev\suvera\snowprint\infra\PublicUrl;
use dev\suvera\snowprint\site\InvalidInput;
use dev\suvera\snowprint\site\InviteEmail;
use dev\suvera\snowprint\site\InviteService;
use dev\suvera\snowprint\site\SiteService;
use dev\suvera\snowprint\site\User;
use dev\suvera\snowprint\site\UserService;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\RestController;
use dev\winterframework\stereotype\web\DeleteMapping;
use dev\winterframework\stereotype\web\GetMapping;
use dev\winterframework\stereotype\web\PathVariable;
use dev\winterframework\stereotype\web\PostMapping;
use dev\winterframework\stereotype\web\PutMapping;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;

/**
 * Users and invites (SP-019), for instance admins. Site roles travel as
 * {"example.com": "viewer" | "admin"}.
 */
#[RestController]
class UsersController {
    use UiApi;
    use Wlf4p;

    #[Autowired]
    private UiSessions $sessions;

    #[Autowired]
    private UserService $users;

    #[Autowired]
    private InviteService $invites;

    #[Autowired]
    private SiteService $sites;

    #[Autowired]
    private Mailer $mailer;

    #[GetMapping(path: '/api/ui/users')]
    public function list(HttpRequest $request): ResponseEntity {
        return self::handle(function () use ($request) {
            $this->admin($request);
            return ResponseEntity::ok()->withJson(['users' => $this->users->all(), 'invites' => $this->invites->pending(),
                'mail_enabled' => $this->mailer->enabled()]);
        });
    }

    /** Body: {"is_admin": false, "sites": {"example.com": "viewer"}} */
    #[PutMapping(path: '/api/ui/users/{id}')]
    public function update(HttpRequest $request, #[PathVariable] int $id): ResponseEntity {
        return self::handle(function () use ($request, $id) {
            self::requireUiHeader($request);
            $this->admin($request);
            $body = self::body($request);
            $user = $this->users->updateAccess($id, ($body['is_admin'] ?? false) === true, $this->siteRoles($body['sites'] ?? []));
            return ResponseEntity::ok()->withJson(['user' => $user->toArray()]);
        });
    }

    #[DeleteMapping(path: '/api/ui/users/{id}')]
    public function delete(HttpRequest $request, #[PathVariable] int $id): ResponseEntity {
        return self::handle(function () use ($request, $id) {
            self::requireUiHeader($request);
            $this->users->delete($this->admin($request), $id);
            return ResponseEntity::ok()->withJson(['deleted' => $id]);
        });
    }

    /**
     * Body: {"email": "...", "is_admin": false, "sites": {"example.com": "viewer"},
     * "dashboard_url": "https://stats.example.com"}; returns the token once. With
     * SMTP configured the link (dashboard_url + /ui/#/invite/<token>) is also
     * emailed; "emailed" says whether that worked, the link is returned either way.
     */
    #[PostMapping(path: '/api/ui/invites')]
    public function invite(HttpRequest $request): ResponseEntity {
        return self::handle(function () use ($request) {
            self::requireUiHeader($request);
            $actor = $this->admin($request);
            $body = self::body($request);
            $dashboard = '';
            if ($this->mailer->enabled()) {
                try {
                    $dashboard = PublicUrl::normalize((string) ($body['dashboard_url'] ?? ''));
                } catch (\InvalidArgumentException) {
                    throw new InvalidInput('"dashboard_url" must be the http(s) address of the dashboard');
                }
            }
            $invite = $this->invites->create($actor, (string) ($body['email'] ?? ''), ($body['is_admin'] ?? false) === true,
                $this->siteRoles($body['sites'] ?? []));
            $result = ['invite' => $invite, 'emailed' => false];
            if ($dashboard !== '') {
                $mail = new InviteEmail($actor->name !== '' ? $actor->name : $actor->email,
                    $dashboard . '/ui/#/invite/' . $invite['token']);
                try {
                    $this->mailer->send($invite['email'], InviteEmail::SUBJECT, $mail->text(), $mail->html());
                    $result['emailed'] = true;
                } catch (MailException $e) {
                    self::logWarning('Invite ' . $invite['id'] . ' created but not emailed: ' . $e->getMessage());
                    $result['email_error'] = $e->getMessage();
                }
            }
            return ResponseEntity::ok()->withJson($result);
        });
    }

    #[DeleteMapping(path: '/api/ui/invites/{id}')]
    public function revokeInvite(HttpRequest $request, #[PathVariable] int $id): ResponseEntity {
        return self::handle(function () use ($request, $id) {
            self::requireUiHeader($request);
            $this->admin($request);
            if (!$this->invites->revoke($id)) {
                throw new UiError(HttpStatus::$NOT_FOUND, 'no open invite with id ' . $id);
            }
            return ResponseEntity::ok()->withJson(['revoked' => $id]);
        });
    }

    private function admin(HttpRequest $request): User {
        $user = $this->sessions->user($this->sessions->open($request));
        if ($user === null) {
            throw new UiError(HttpStatus::$UNAUTHORIZED, 'sign in first');
        }
        if (!$user->isAdmin) {
            throw new UiError(HttpStatus::$FORBIDDEN, 'only admins can manage users');
        }
        return $user;
    }

    /** @return array<int, string> site id => role */
    private function siteRoles(mixed $sites): array {
        if (!is_array($sites)) {
            throw new InvalidInput('"sites" must be an object of domain => role');
        }
        $roles = [];
        foreach ($sites as $domain => $role) {
            $roles[$this->sites->idsForDomains([(string) $domain])[0]] = (string) $role;
        }
        return $roles;
    }
}
