<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/** Small value-object for legacy X-RateLimit response headers. */
final class RateLimit
{
    /** @return array<int,array{string,string}> */
    public static function forUser(int $limit, int $remaining, int $resetEpoch): array
    {
        if ($limit < 0 || $remaining < 0 || $resetEpoch < 0) {
            throw new \InvalidArgumentException('Rate-limit values must be zero or greater.');
        }
        if ($remaining > $limit) {
            throw new \InvalidArgumentException('Rate-limit remaining value cannot exceed the limit.');
        }

        return [
            ['X-RateLimit-Limit', (string) $limit],
            ['X-RateLimit-Remaining', (string) $remaining],
            ['X-RateLimit-Reset', (string) $resetEpoch],
        ];
    }

    /** @return array{string,string} */
    public static function retryAfter(int $seconds): array
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Retry-After seconds must be zero or greater.');
        }

        return ['Retry-After', (string) $seconds];
    }
}
