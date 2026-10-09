<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\rollup;

use dev\suvera\snowprint\privacy\SaltService;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;
use dev\winterframework\task\scheduling\stereotype\Scheduled;
use dev\winterframework\util\log\Wlf4p;

/**
 * Discards visitor-hash salts older than yesterday (PRD §9.3: ~48 hours).
 */
#[Component]
class SaltExpiryJob {
    use Wlf4p;

    #[Autowired]
    private SaltService $salts;

    #[Scheduled(fixedDelay: 900, initialDelay: 60)]
    public function purge(): void {
        try {
            $removed = $this->salts->purgeExpired();
            if ($removed > 0) {
                self::logInfo("Discarded $removed expired salt(s)");
            }
        } catch (\Throwable $e) {
            self::logException($e, 'Salt expiry failed. ');
        }
    }
}
