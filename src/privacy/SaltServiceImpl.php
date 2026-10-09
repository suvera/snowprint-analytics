<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\privacy;

use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

/**
 * Caches today's salt in worker memory; the store is touched once per worker
 * per UTC day.
 */
#[Service]
class SaltServiceImpl implements SaltService {

    #[Autowired]
    private SaltStore $store;

    private string $cachedDay = '';
    private string $cachedSalt = '';

    public function currentSalt(): string {
        $day = $this->today();
        if ($day !== $this->cachedDay) {
            $this->cachedSalt = $this->store->getOrCreate($day, random_bytes(self::SALT_BYTES));
            $this->cachedDay = $day;
        }
        return $this->cachedSalt;
    }

    public function purgeExpired(): int {
        $yesterday = (new \DateTimeImmutable($this->today() . ' UTC'))->modify('-1 day')->format('Y-m-d');
        return $this->store->deleteBefore($yesterday);
    }

    protected function today(): string {
        return gmdate('Y-m-d');
    }
}
