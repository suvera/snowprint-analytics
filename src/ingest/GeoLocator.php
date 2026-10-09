<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\util\log\Wlf4p;
use MaxMind\Db\Reader;

/**
 * Country, region and city for an IP from a user-supplied MaxMind-format
 * database (decision D3: DB-IP Lite or GeoLite2). Without a database every
 * lookup returns nulls. Only the result is stored, never the IP.
 */
#[Component]
class GeoLocator {
    use Wlf4p;

    #[Autowired]
    private ApplicationContext $ctx;

    private ?Reader $reader = null;
    private bool $opened = false;

    /** @return array{country: ?string, region: ?string, city: ?string} */
    public function locate(string $ip): array {
        $reader = $this->reader();
        if ($reader === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return self::fromRecord(null);
        }
        try {
            $record = $reader->get($ip);
        } catch (\Throwable) {
            $record = null;
        }
        return self::fromRecord(is_array($record) ? $record : null);
    }

    /** @return array{country: ?string, region: ?string, city: ?string} */
    public static function fromRecord(?array $record): array {
        $country = $record['country']['iso_code'] ?? null;
        return [
            'country' => is_string($country) && strlen($country) === 2 ? strtoupper($country) : null,
            'region' => self::name($record['subdivisions'][0] ?? null),
            'city' => self::name($record['city'] ?? null),
        ];
    }

    private static function name(mixed $node): ?string {
        $name = is_array($node) ? ($node['names']['en'] ?? null) : null;
        return is_string($name) && $name !== '' ? mb_substr($name, 0, 255) : null;
    }

    private function reader(): ?Reader {
        if (!$this->opened) {
            $this->opened = true;
            $path = trim($this->ctx->getPropertyStr('snowprint.geoip.database', ''));
            if ($path !== '') {
                try {
                    $this->reader = new Reader($path);
                } catch (\Throwable $e) {
                    self::logError('GeoIP database could not be opened; locations stay empty: ' . $e->getMessage());
                }
            }
        }
        return $this->reader;
    }
}
