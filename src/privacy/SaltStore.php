<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\privacy;

/**
 * Persistence for the daily salts (table `salts`).
 */
interface SaltStore {

    /**
     * Returns the salt for $day (Y-m-d, UTC), creating it from $candidate if
     * none exists yet. Concurrent callers always get the same salt.
     */
    public function getOrCreate(string $day, string $candidate): string;

    /** Deletes salts for days before $keepFromDay (Y-m-d). Returns rows removed. */
    public function deleteBefore(string $keepFromDay): int;
}
