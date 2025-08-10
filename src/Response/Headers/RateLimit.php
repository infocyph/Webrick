<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * Small value-object representing X-RateLimit headers.
 *
 * Usage:
 * ```php
 * $resp = $resp
 *     ->withHeader(... RateLimit::forUser($limit,$remain,$reset) );
 * ```
 */
final class RateLimit
{
    /** Generate three header tuples. */
    public static function forUser(int $limit, int $remaining, int $resetEpoch): array
    {
        return [
            ['X-RateLimit-Limit', (string)$limit],
            ['X-RateLimit-Remaining', (string)$remaining],
            ['X-RateLimit-Reset', (string)$resetEpoch],
        ];
    }

    /** Retry-After header helper (seconds). */
    public static function retryAfter(int $seconds): array
    {
        return ['Retry-After', (string)$seconds];
    }
}
