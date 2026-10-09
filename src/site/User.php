<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

/**
 * A dashboard user. Instance admins read and manage everything: sites, users
 * and invites. Other users see only the sites granted in site_users, as
 * "viewer" (reports) or "admin" (reports, goals and the site's settings).
 */
final class User {

    public const ROLES = ['viewer', 'admin'];

    /** @param array<int, string> $siteRoles site id => role, from site_users */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly bool $isAdmin,
        public readonly array $siteRoles = [],
    ) {
    }

    public function canRead(int $siteId): bool {
        return $this->isAdmin || isset($this->siteRoles[$siteId]);
    }

    public function canManage(int $siteId): bool {
        return $this->isAdmin || ($this->siteRoles[$siteId] ?? null) === 'admin';
    }

    /** @return array{id: int, email: string, name: string, is_admin: bool} */
    public function toArray(): array {
        return ['id' => $this->id, 'email' => $this->email, 'name' => $this->name, 'is_admin' => $this->isAdmin];
    }
}
