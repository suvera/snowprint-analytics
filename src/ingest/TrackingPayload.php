<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

/**
 * The tracker request body (`POST /api/event`, JSON, sent as text/plain by
 * sendBeacon). Short keys keep snow.js small:
 *
 *   d  site domain (required)     u  page URL (required, http/https)
 *   n  event name (default "pageview")
 *   r  referrer URL               t  page title
 *   w  screen width               h  screen height
 *   p  custom properties: flat object of scalars
 */
final class TrackingPayload {

    public const MAX_BODY_BYTES = 8192;
    public const MAX_PROPS = 30;
    public const MAX_PROP_KEY = 64;
    public const MAX_PROP_VALUE = 2000;
    public const PAGEVIEW = 'pageview';

    /** @param array<string, string|int|float|bool> $props */
    private function __construct(
        public readonly string $domain,
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $referrer,
        public readonly ?string $title,
        public readonly ?int $screenWidth,
        public readonly ?int $screenHeight,
        public readonly array $props,
    ) {
    }

    public static function parse(string $body): self {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidPayload('payload too large');
        }
        $data = json_decode($body, true);
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidPayload('body must be a JSON object');
        }

        $domain = self::str($data, 'd', 255);
        $url = self::str($data, 'u', 2048);
        if ($domain === null || $url === null) {
            throw new InvalidPayload('"d" and "u" are required');
        }
        if (!preg_match('#^https?://#i', $url)) {
            throw new InvalidPayload('"u" must be an http(s) URL');
        }
        $name = self::str($data, 'n', 120) ?? self::PAGEVIEW;

        return new self(
            $domain,
            $name,
            $url,
            self::str($data, 'r', 2048),
            self::str($data, 't', 1000),
            self::dimension($data, 'w'),
            self::dimension($data, 'h'),
            self::props($data['p'] ?? null),
        );
    }

    private static function str(array $data, string $key, int $max): ?string {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function dimension(array $data, string $key): ?int {
        $value = $data[$key] ?? null;
        return is_int($value) && $value > 0 && $value <= 32767 ? $value : null;
    }

    /** @return array<string, string|int|float|bool> */
    private static function props(mixed $raw): array {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return [];
        }
        $props = [];
        foreach ($raw as $key => $value) {
            if (count($props) >= self::MAX_PROPS) {
                break;
            }
            if (!is_string($key) || $key === '' || strlen($key) > self::MAX_PROP_KEY) {
                continue;
            }
            if (is_string($value)) {
                $props[$key] = mb_substr($value, 0, self::MAX_PROP_VALUE);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $props[$key] = $value;
            }
        }
        return $props;
    }
}
