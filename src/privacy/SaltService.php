<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\privacy;

/**
 * Daily rotating salt for visitor hashing (PRD §9.3).
 */
interface SaltService {

    public const SALT_BYTES = 32;

    /** Today's salt (UTC day). */
    public function currentSalt(): string;

    /** Deletes salts older than yesterday, so none outlives ~48 hours. */
    public function purgeExpired(): int;
}
