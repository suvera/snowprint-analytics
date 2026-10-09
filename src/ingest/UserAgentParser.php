<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

use DeviceDetector\DeviceDetector;
use DeviceDetector\Yaml\Symfony;

/**
 * Browser, OS and device class from a user agent (decision D1:
 * matomo/device-detector, LGPL-3.0, used unmodified). Parsing costs about a
 * millisecond, so results are cached per worker; real traffic repeats a small
 * set of user agents.
 *
 * warmUp() runs at worker start (IngestWarmUp): device-detector loads its YAML
 * regex files on first use, and with Swoole's file hooks concurrent cold
 * requests would each load a copy and exhaust memory.
 */
final class UserAgentParser {

    public const CACHE_SIZE = 10_000;

    /** @var array<string, UserAgentInfo> */
    private static array $cache = [];

    public static function parse(string $userAgent): UserAgentInfo {
        if (isset(self::$cache[$userAgent])) {
            return self::$cache[$userAgent];
        }
        if (count(self::$cache) >= self::CACHE_SIZE) {
            self::$cache = [];
        }
        return self::$cache[$userAgent] = self::detect($userAgent);
    }

    /** Loads every regex set once, before the worker serves requests. */
    public static function warmUp(): void {
        foreach ([
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ] as $agent) {
            self::detect($agent);
        }
    }

    private static function detect(string $userAgent): UserAgentInfo {
        $dd = new DeviceDetector($userAgent);
        $dd->setYamlParser(new Symfony());
        $dd->discardBotInformation();
        $dd->parse();
        if ($dd->isBot()) {
            return new UserAgentInfo(true);
        }
        return new UserAgentInfo(
            false,
            self::value($dd->getClient('name'), 64),
            self::value($dd->getClient('version'), 32),
            self::value($dd->getOs('name'), 64),
            self::value($dd->getOs('version'), 32),
            self::deviceClass($dd),
        );
    }

    private static function deviceClass(DeviceDetector $dd): string {
        if ($dd->isTablet()) {
            return UserAgentInfo::TABLET;
        }
        if ($dd->isMobile()) {
            return UserAgentInfo::MOBILE;
        }
        if ($dd->isDesktop()) {
            return UserAgentInfo::DESKTOP;
        }
        return UserAgentInfo::OTHER;
    }

    private static function value(mixed $value, int $max): ?string {
        if (!is_string($value) || $value === '' || $value === DeviceDetector::UNKNOWN) {
            return null;
        }
        return mb_substr($value, 0, $max);
    }
}
