<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

/**
 * Names the traffic source shown in reports: `utm_source` when the link was
 * tagged, otherwise a friendly name for well-known referrer hosts, otherwise
 * the referrer host itself. Null means direct traffic.
 */
final class ReferrerSource {

    /** Host pattern (matched against the host without "www.") => source name. */
    private const KNOWN = [
        '/(^|\.)google\.[a-z.]+$/' => 'Google',
        '/(^|\.)bing\.com$/' => 'Bing',
        '/(^|\.)duckduckgo\.com$/' => 'DuckDuckGo',
        '/(^|\.)search\.yahoo\.[a-z.]+$|(^|\.)yahoo\.com$/' => 'Yahoo',
        '/(^|\.)yandex\.[a-z.]+$|(^|\.)ya\.ru$/' => 'Yandex',
        '/(^|\.)baidu\.com$/' => 'Baidu',
        '/(^|\.)ecosia\.org$/' => 'Ecosia',
        '/(^|\.)search\.brave\.com$/' => 'Brave Search',
        '/(^|\.)kagi\.com$/' => 'Kagi',
        '/(^|\.)perplexity\.ai$/' => 'Perplexity',
        '/(^|\.)chatgpt\.com$|(^|\.)chat\.openai\.com$/' => 'ChatGPT',
        '/(^|\.)claude\.ai$/' => 'Claude',
        '/(^|\.)news\.ycombinator\.com$/' => 'Hacker News',
        '/(^|\.)reddit\.com$|(^|\.)redd\.it$/' => 'Reddit',
        '/(^|\.)lobste\.rs$/' => 'Lobsters',
        '/(^|\.)github\.com$/' => 'GitHub',
        '/(^|\.)gitlab\.com$/' => 'GitLab',
        '/(^|\.)stackoverflow\.com$/' => 'Stack Overflow',
        '/(^|\.)dev\.to$/' => 'DEV',
        '/(^|\.)medium\.com$/' => 'Medium',
        '/(^|\.)producthunt\.com$/' => 'Product Hunt',
        '/(^|\.)twitter\.com$|(^|\.)x\.com$|^t\.co$/' => 'X (Twitter)',
        '/(^|\.)facebook\.com$|^fb\.me$/' => 'Facebook',
        '/(^|\.)instagram\.com$/' => 'Instagram',
        '/(^|\.)linkedin\.com$|^lnkd\.in$/' => 'LinkedIn',
        '/(^|\.)youtube\.com$|^youtu\.be$/' => 'YouTube',
        '/(^|\.)bsky\.app$/' => 'Bluesky',
        '/(^|\.)mastodon\.[a-z.]+$|(^|\.)mastodon\.social$/' => 'Mastodon',
        '/(^|\.)threads\.(net|com)$/' => 'Threads',
        '/(^|\.)pinterest\.[a-z.]+$/' => 'Pinterest',
        '/(^|\.)tiktok\.com$/' => 'TikTok',
        '/(^|\.)wikipedia\.org$/' => 'Wikipedia',
    ];

    public static function name(?string $referrerHost, ?string $utmSource): ?string {
        if ($utmSource !== null && $utmSource !== '') {
            return $utmSource;
        }
        if ($referrerHost === null) {
            return null;
        }
        $host = str_starts_with($referrerHost, 'www.') ? substr($referrerHost, 4) : $referrerHost;
        // Email webmail hosts first: mail.google.com must not become "Google".
        if (preg_match('/^mail\.google\.com$|(^|\.)outlook\.(live|office)\.com$|(^|\.)mail\.yahoo\.com$/', $host)) {
            return 'Email';
        }
        foreach (self::KNOWN as $pattern => $name) {
            if (preg_match($pattern, $host)) {
                return $name;
            }
        }
        return $host;
    }
}
