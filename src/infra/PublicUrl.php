<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;

/**
 * The public tracking URL (snowprint.publicUrl / SNOWPRINT_PUBLIC_URL): where
 * websites load /snow.js from and send events to. May differ from the address
 * the dashboard is served on.
 */
#[Component]
class PublicUrl {

    #[Autowired]
    private ApplicationContext $ctx;

    private ?string $url = null;

    /** Normalised base URL without a trailing slash, or '' when not configured. */
    public function get(): string {
        return $this->url ??= self::normalize($this->ctx->getPropertyStr('snowprint.publicUrl', ''));
    }

    public static function normalize(string $value): string {
        $value = rtrim(trim($value), '/');
        if ($value === '') {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException(
                'snowprint.publicUrl must be an http(s) URL such as https://stats.example.com, got: ' . $value);
        }
        return $value;
    }
}
