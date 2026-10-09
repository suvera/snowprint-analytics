<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

/**
 * A dashboard user. Admins can read and manage every site; other users only
 * the sites they were given (site_users).
 */
final class User {

    /** @param list<int> $siteIds sites granted through site_users */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly bool $isAdmin,
        public readonly array $siteIds,
    ) {
    }

    public function canRead(int $siteId): bool {
        return $this->isAdmin || in_array($siteId, $this->siteIds, true);
    }

    /** @return array{id: int, email: string, name: string, is_admin: bool} */
    public function toArray(): array {
        return ['id' => $this->id, 'email' => $this->email, 'name' => $this->name, 'is_admin' => $this->isAdmin];
    }
}
