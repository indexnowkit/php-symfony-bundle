<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Check;

use IndexNowKit\Check\DebounceStoreCheck;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The probe of the core's `Check\DebounceStoreCheck` for the bundle: writes the core's test key through the PSR-16
 * view of the pool `debounce.store` names (the same `Psr16Cache` the debounce store uses) and returns the pool's
 * signature (`cache pool "cache.app" (ArrayAdapter)`), or lets the pool's exception through. Parity with the Laravel
 * and Yii adapters, which probe their cache stores the same way.
 */
final class CacheProbe
{
    public function __construct(private readonly CacheInterface $cache, private readonly CacheItemPoolInterface $pool) {}

    public function __invoke(string $store): string
    {
        $this->cache->set(DebounceStoreCheck::PROBE_KEY, 1, 5);

        return \sprintf('cache pool "%s" (%s)', $store, get_debug_type($this->pool));
    }
}
