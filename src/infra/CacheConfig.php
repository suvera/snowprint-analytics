<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\CacheManager;
use dev\winterframework\cache\impl\InMemoryCache;
use dev\winterframework\cache\impl\SimpleCacheManager;
use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\Configuration;

/**
 * Caches behind #[Cacheable]. In memory, so each worker process has its own
 * copy; entries never leave the process and are never logged.
 */
#[Configuration]
class CacheConfig {

    /** Resolved API keys by key digest; a revoked key stops working within 30 s on every worker. */
    public const API_KEYS = 'api-keys';

    #[Bean]
    public function cacheManager(): CacheManager {
        $manager = new SimpleCacheManager();
        $manager->addCache(new InMemoryCache(self::API_KEYS,
            CacheConfiguration::get(maximumSize: 1000, expireAfterWriteMs: 30_000)));
        return $manager;
    }
}
