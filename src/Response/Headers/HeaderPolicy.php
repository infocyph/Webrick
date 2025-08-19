<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

final class HeaderPolicy
{
    public const SINGLE = 0;
    public const MULTI_LINE = 1;
    public const MERGE_TOKENS = 2;

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

    public static function register(string $header, int $policy): void
    {
        self::$map[strtolower($header)] = $policy;
    }

    /* ---------------- NEW: central merge for CSV headers ---------------- */

    // Infocyph\Webrick\Response\Headers\HeaderPolicy

    public static function mergeCsv(string $name, string $existing, string $incoming): string|array
    {
        if ($existing === '') {
            return self::normalizeCsv($incoming);
        }

        if (strtolower($name) === 'cache-control') {
            return CacheControl::canonicalizeMerge($existing, $incoming);
        }

        $seen = [];
        $out  = [];
        foreach ([self::normalizeCsv($existing), self::normalizeCsv($incoming)] as $list) {
            foreach ($list as $tok) {
                $k = strtolower($tok);
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $out[] = $tok;
                }
            }
        }
        return implode(', ', $out);
    }

    private static function normalizeCsv(string $csv): array
    {
        $toks = array_map('trim', explode(',', $csv));
        $toks = array_values(array_filter($toks, fn ($t) => $t !== ''));
        // Title-Case typical header tokens (Accept-Encoding, Origin, etc.)
        return array_map(static function (string $t): string {
            foreach (explode('-', $t) as $i => $part) {
                $parts[$i] = $part === '' ? '' : ucfirst(strtolower($part));
            }
            return implode('-', $parts ?? []);
        }, $toks);
    }



    private function __construct()
    {
    }
}
