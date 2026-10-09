<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

/**
 * An authenticated API key. $siteIds is null when the key covers all sites.
 */
final class ApiKey {

    /** @param list<int>|null $siteIds */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?array $siteIds,
        public readonly bool $canWrite,
    ) {
    }

    public function canRead(int $siteId): bool {
        return $this->siteIds === null || in_array($siteId, $this->siteIds, true);
    }
}
