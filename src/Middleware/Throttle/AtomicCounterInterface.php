<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware\Throttle;

/** Atomic fixed-window counter required for production/concurrent throttling. */
interface AtomicCounterInterface
{
    /**
     * Return the counter value after applying delta with the supplied TTL.
     * @param string $key
     * @param int $delta
     * @param int $ttlSeconds
     */
    public function increment(string $key, int $delta, int $ttlSeconds): int;
}
