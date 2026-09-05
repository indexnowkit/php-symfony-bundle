<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Check;

use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The probe of the core's `Check\DebounceStoreCheck` for the bundle: reads a test key through the PSR-16 view of
 * the pool `debounce.store` names (the same `Psr16Cache` the debounce store uses) and returns the pool's signature
 * (`cache pool "cache.app" (ArrayAdapter)`), or lets the pool's exception through. Parity with the Laravel and
 * Yii2 adapters, which probe their cache stores the same way.
 */
final class CacheProbe
{
    public function __construct(private readonly CacheInterface $cache, private readonly CacheItemPoolInterface $pool) {}

    public function __invoke(string $store): string
    {
        $this->cache->get('indexnowkit_check');

        return \sprintf('cache pool "%s" (%s)', $store, get_debug_type($this->pool));
    }
}
