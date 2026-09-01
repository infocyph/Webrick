<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/** Numeric helpers for restrictive Cache-Control directive merging. */
final class CacheControlNumber
{
    public static function min(?int $first, ?int $second): ?int
    {
        if ($first === null) {
            return $second;
        }

        return $second === null ? $first : min($first, $second);
    }
}
