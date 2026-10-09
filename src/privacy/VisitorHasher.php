<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\privacy;

/**
 * Derives the anonymous visitor id (PRD §9.3, decision D2):
 * keyed BLAKE2b-128 over site id, IP and user agent, keyed with the daily salt.
 * The IP and user agent are only used here, in memory, and never stored.
 */
final class VisitorHasher {

    public const HASH_BYTES = 16;

    public static function hash(string $salt, int $siteId, string $ip, string $userAgent): string {
        // Fixed-width site id plus a separator no IP contains keeps inputs unambiguous.
        $message = pack('J', $siteId) . $ip . "\n" . $userAgent;
        return sodium_crypto_generichash($message, $salt, self::HASH_BYTES);
    }
}
