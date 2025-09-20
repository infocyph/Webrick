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
    /**
     * Build the standard X-RateLimit headers for a user.
     *
     * Produces three header tuples:
     *  - X-RateLimit-Limit: total allowed requests for the period
     *  - X-RateLimit-Remaining: remaining requests in the current period
     *  - X-RateLimit-Reset: UNIX epoch timestamp when the quota resets
     *
     * @param int $limit Total allowed requests for the window.
     * @param int $remaining Requests remaining in the current window.
     * @param int $resetEpoch UNIX epoch seconds when the limit will reset.
     * @return array<int, array{string,string}> Array of header [name, value] tuples.
     */
    public static function forUser(int $limit, int $remaining, int $resetEpoch): array
    {
        return [
            ['X-RateLimit-Limit', (string)$limit],
            ['X-RateLimit-Remaining', (string)$remaining],
            ['X-RateLimit-Reset', (string)$resetEpoch],
        ];
    }

    /**
     * Create a Retry-After header tuple using a relative delay in seconds.
     *
     * Use this when instructing clients how many seconds to wait before retrying.
     *
     * @param int $seconds Delay in seconds.
     * @return array{string,string} Header tuple ['Retry-After', '<seconds>'].
     */
    public static function retryAfter(int $seconds): array
    {
        return ['Retry-After', (string)$seconds];
    }
}
