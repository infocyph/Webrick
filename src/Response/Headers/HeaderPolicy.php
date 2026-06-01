<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

final class HeaderPolicy
{
    public const int MERGE_TOKENS = 2;

    public const int MULTI_LINE = 1;

    public const int SINGLE = 0;

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

    /**
     * Private constructor to prevent instantiation.
     *
     * The class provides only static helpers; creating an instance is not intended.
     */
    private function __construct() {}

    /**
     * Return the merge policy for a header name.
     *
     * Looks up a header name (case-insensitive) and returns one of the
     * policy constants: SINGLE, MULTI_LINE or MERGE_TOKENS. Unknown headers
     * default to SINGLE.
     *
     * @param string $header Header name (e.g. "Cache-Control")
     * @return int One of the HeaderPolicy::* constants
     */
    public static function for(string $header): int
    {
        return self::$map[strtolower($header)] ?? self::SINGLE;
    }

    /* ---------------- NEW: central merge for CSV headers ---------------- */
    // Infocyph\Webrick\Response\Headers\HeaderPolicy
    /**
     * Merge two comma-separated header values into a canonical representation.
     *
     * - If $existing is empty, returns the normalized tokens from $incoming.
     * - Cache-Control is handled specially via CacheControl::canonicalizeMerge.
     * - For other headers tokens are normalized, de-duplicated (case-insensitive)
     *   and returned as a single comma+space separated string.
     *
     * @param string $name Header name (used to select special-case logic)
     * @param string $existing Current header value (may be empty)
     * @param string $incoming New header value to merge in
     * @return string Merged header string, or other canonical form returned by special cases
     */
    public static function mergeCsv(string $name, string $existing, string $incoming): string
    {
        if ($existing === '') {
            return implode(', ', self::normalizeCsv($incoming));
        }

        if (strtolower($name) === 'cache-control') {
            return CacheControl::canonicalizeMerge($existing, $incoming);
        }

        $seen = [];
        $out = [];
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

    /**
     * Register or override the merge policy for a header name.
     *
     * This allows adding custom header handling rules at runtime. The
     * header name is stored in lowercase.
     *
     * @param string $header Header name to register (case-insensitive)
     * @param int $policy One of the HeaderPolicy::* constants
     */
    public static function register(string $header, int $policy): void
    {
        self::$map[strtolower($header)] = $policy;
    }

    /**
     * Normalize a comma-separated header value into an array of tokens.
     *
     * - Splits on commas, trims tokens and removes empty entries.
     * - Normalizes hyphenated tokens to Title-Case (e.g. "accept-encoding" -> "Accept-Encoding").
     *
     * @param string $csv Comma-separated header value
     * @return array<int,string> Array of normalized token strings
     */
    private static function normalizeCsv(string $csv): array
    {
        $toks = array_map(trim(...), explode(',', $csv));
        $toks = array_values(array_filter($toks, fn($t) => $t !== ''));

        // Title-Case typical header tokens (Accept-Encoding, Origin, etc.)
        return array_map(static function (string $t): string {
            $parts = array_map(fn($part) => $part === '' ? '' : ucfirst(strtolower($part)), explode('-', $t));

            return implode('-', $parts);
        }, $toks);
    }
}
