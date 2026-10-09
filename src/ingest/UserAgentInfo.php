<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

/**
 * What we keep from a user agent. The user agent itself is never stored.
 */
final class UserAgentInfo {

    public const DESKTOP = 'desktop';
    public const MOBILE = 'mobile';
    public const TABLET = 'tablet';
    public const OTHER = 'other';

    public function __construct(
        public readonly bool $bot,
        public readonly ?string $browser = null,
        public readonly ?string $browserVersion = null,
        public readonly ?string $os = null,
        public readonly ?string $osVersion = null,
        public readonly ?string $device = null,
    ) {
    }
}
