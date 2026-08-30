<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interop\CacheLayer;

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\Webrick\Middleware\Throttle\AtomicCounterInterface;

/** Optional CacheLayer bridge for Webrick's production throttle counter contract. */
final readonly class AtomicCounterAdapter implements AtomicCounterInterface
{
    public function __construct(private AtomicCounterStoreInterface $store) {}

    public function increment(string $key, int $delta, int $ttlSeconds): int
    {
        return $this->store->increment($key, $delta, $ttlSeconds)->value;
    }
}
