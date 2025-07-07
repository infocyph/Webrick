<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Support;

/**
 * Very small set of cross-cutting helpers.
 */
final class Utils
{
    private function __construct()
    {
    }   // static-only

    /**
     * Remove **all** leading / trailing slashes – useful before exploding.
     */
    public static function trimSlashes(string $path): string
    {
        return trim($path, '/');
    }

    /**
     * Build a well-formed URI from heterogeneous bits.
     *
     * ```php
     * Utils::buildUri('/v1', 'user', 42);   // "/v1/user/42"
     * ```
     *
     * @param array<int,string|int|float> $segments
     */
    public static function buildUri(string|int|float ...$segments): string
    {
        $clean = array_map(
            static fn ($s) => self::trimSlashes((string) $s),
            $segments
        );

        return '/' . implode('/', array_filter($clean, static fn ($v) => $v !== ''));
    }
}
