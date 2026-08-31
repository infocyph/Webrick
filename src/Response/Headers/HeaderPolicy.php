<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * Field-specific response header combination policy.
 */
final class HeaderPolicy
{
    public const int MERGE_TOKENS = 2;

    public const int MULTI_LINE = 1;

    public const int SINGLE = 0;

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
                $key = strtolower($token);
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
        if (!in_array($policy, [self::SINGLE, self::MULTI_LINE, self::MERGE_TOKENS], true)) {
            throw new \InvalidArgumentException('Unknown header merge policy.');
        }

        self::$map[strtolower($header)] = $policy;
    }

    private static function canonicalHeaderToken(string $token): string
    {
        $parts = explode('-', strtolower($token));

        return implode('-', array_map(ucfirst(...), $parts));
    }

    /**
     * @return list<string>
     * @param string $lowerName
     * @param string $csv
     */
    private static function normalizeCsv(string $lowerName, string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $out[] = match ($lowerName) {
                'allow', 'access-control-allow-methods' => strtoupper($token),
                'access-control-allow-headers', 'vary' => self::canonicalHeaderToken($token),
                default => $token,
            };
        }

        return $out;
    }
}
