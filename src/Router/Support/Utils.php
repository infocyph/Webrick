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
            static fn ($s) => trim($s, '/'),
            $segments
        );

        return '/' . implode('/', array_filter($clean, static fn ($v) => $v !== ''));
    }

    public static function normaliseHost(string $raw): string
    {
        // ① basic sanity
        if ($raw === '' || \preg_match('/[\x00-\x20]/', $raw)) {
            throw new \InvalidArgumentException('Illegal Host header.');
        }

        // ② trim trailing dot & lowercase
        $host = \rtrim(\strtolower($raw), '.');

        // ③ IDN → ASCII
        if (\function_exists('idn_to_ascii') && !\str_contains($host, 'xn--')) {
            $ascii = @\idn_to_ascii($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException('Invalid IDN host name.');
            }
            $host = $ascii;
        }

        // ④ ASCII-only guard if intl is missing
        if (!\preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException('Host contains non-ASCII bytes.');
        }

        return $host;
    }
}
