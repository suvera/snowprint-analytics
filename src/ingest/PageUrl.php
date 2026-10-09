<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

/**
 * Splits a page URL into what we store: hostname, path and UTM tags. Other
 * query parameters and fragments are dropped (they often carry personal data).
 */
final class PageUrl {

    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** @param array<string, string> $utm */
    private function __construct(
        public readonly ?string $hostname,
        public readonly string $path,
        public readonly array $utm,
    ) {
    }

    public static function parse(string $url): self {
        $parts = parse_url($url) ?: [];
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        $utm = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (self::UTM_KEYS as $key) {
                if (isset($query[$key]) && is_string($query[$key]) && trim($query[$key]) !== '') {
                    $utm[$key] = mb_substr(trim($query[$key]), 0, 255);
                }
            }
        }
        return new self($host, mb_substr($path, 0, 2048), $utm);
    }

    /** Referrer host, or null for direct traffic and same-site navigation. */
    public static function referrerHost(?string $referrer, ?string $pageHost): ?string {
        if ($referrer === null) {
            return null;
        }
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        $strip = static fn(string $h) => str_starts_with($h, 'www.') ? substr($h, 4) : $h;
        if ($pageHost !== null && $strip($host) === $strip($pageHost)) {
            return null;
        }
        return mb_substr($host, 0, 255);
    }
}
