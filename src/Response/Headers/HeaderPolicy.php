<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Constants\HttpMethodEnum;

/** Field-specific response header combination policy, mutable only before production freeze. */
final class HeaderPolicy
{
    public const int MERGE_TOKENS = 2;

    public const int MULTI_LINE = 1;

    public const int SINGLE = 0;

    private const string FIELD_NAME_RX = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

    private static bool $frozen = false;

    /** @var array<string,int> */
    private static array $map = [
        'content-length' => self::SINGLE,
        'content-type' => self::SINGLE,
        'etag' => self::SINGLE,
        'last-modified' => self::SINGLE,
        'location' => self::SINGLE,
        'set-cookie' => self::MULTI_LINE,
        'link' => self::MULTI_LINE,
        'allow' => self::MERGE_TOKENS,
        'vary' => self::MERGE_TOKENS,
        'access-control-allow-methods' => self::MERGE_TOKENS,
        'access-control-allow-headers' => self::MERGE_TOKENS,
        'cache-control' => self::MERGE_TOKENS,
    ];

    private function __construct() {}

    public static function for(string $header): int
    {
        return self::$map[strtolower($header)] ?? self::SINGLE;
    }

    public static function freeze(): void
    {
        self::$frozen = true;
    }

    public static function mergeCsv(string $name, string $existing, string $incoming): string
    {
        $lowerName = strtolower($name);
        if ($lowerName === 'cache-control') {
            return CacheControl::canonicalizeMerge($existing, $incoming);
        }

        $seen = [];
        $out = [];
        foreach ([$existing, $incoming] as $value) {
            foreach (self::normalizeCsv($lowerName, $value) as $token) {
                $key = self::tokenKey($lowerName, $token);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = $token;
                }
            }
        }

        return implode(', ', $out);
    }

    public static function register(string $header, int $policy): void
    {
        if (self::$frozen) {
            throw new \LogicException('Header policy registry is frozen for production runtime.');
        }
        if (preg_match(self::FIELD_NAME_RX, $header) !== 1) {
            throw new \InvalidArgumentException('Header policy name must be a valid HTTP field name.');
        }
        if (!in_array($policy, [self::SINGLE, self::MULTI_LINE, self::MERGE_TOKENS], true)) {
            throw new \InvalidArgumentException('Unknown header merge policy.');
        }

        self::$map[strtolower($header)] = $policy;
    }

    private static function canonicalHeaderToken(string $token): string
    {
        if ($token !== '*' && preg_match(self::FIELD_NAME_RX, $token) !== 1) {
            throw new \InvalidArgumentException('Merged header token must be a valid HTTP field name or wildcard.');
        }

        return $token === '*' ? '*' : implode('-', array_map(ucfirst(...), explode('-', strtolower($token))));
    }

    /** @return list<string> */
    private static function normalizeCsv(string $lowerName, string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $out[] = match ($lowerName) {
                'allow', 'access-control-allow-methods' => HttpMethodEnum::normalize($token),
                'access-control-allow-headers', 'vary' => self::canonicalHeaderToken($token),
                default => $token,
            };
        }

        return $out;
    }

    private static function tokenKey(string $lowerName, string $token): string
    {
        return $lowerName === 'allow' || $lowerName === 'access-control-allow-methods'
            ? $token
            : strtolower($token);
    }
}
