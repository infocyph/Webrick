<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

final class HeaderPolicy
{
    public const SINGLE = 0;   // last write wins        → Content-Length
    public const MULTI_LINE = 1;   // allow duplicates       → Set-Cookie
    public const MERGE_TOKENS = 2;   // CSV, de-dupe tokens    → Vary

    /** @var array<string,int> lowercase-header => policy */
    private static array $map = [
        'content-length' => self::SINGLE,
        'content-type' => self::SINGLE,
        'etag' => self::SINGLE,
        'last-modified' => self::SINGLE,
        'location' => self::SINGLE,

        'set-cookie' => self::MULTI_LINE,
        'link' => self::MULTI_LINE,

        'vary' => self::MERGE_TOKENS,
        'access-control-allow-methods' => self::MERGE_TOKENS,
        'access-control-allow-headers' => self::MERGE_TOKENS,
        'cache-control' => self::MERGE_TOKENS,
    ];

    public static function for(string $header): int
    {
        return self::$map[strtolower($header)] ?? self::SINGLE;
    }

    /** Let frameworks or packages register their own semantics. */
    public static function register(string $header, int $policy): void
    {
        self::$map[strtolower($header)] = $policy;
    }

    private function __construct() {}
}
