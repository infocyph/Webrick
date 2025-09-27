<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * Choose best language for response and build related headers.
 *
 * Simple prefix-match algorithm, no q-value weighting (keep hot-path fast).
 */
final class Language
{
    /**
     * Build the Content-Language and Vary headers for the chosen language.
     *
     * Returns an array of header tuples suitable for appending to a response:
     * [
     *   ['Content-Language', $chosen],
     *   ['Vary', 'Accept-Language'],
     * ]
     *
     * @param string $chosen The selected language tag (result of negotiate()).
     * @return array<int, array{string,string}> Array of header name/value pairs.
     */
    public static function headers(string $chosen): array
    {
        return [
            ['Content-Language', $chosen],
            ['Vary', 'Accept-Language'],
        ];
    }

    /**
     * Determine the best matching language from an Accept-Language header.
     *
     * Behaviour:
     * - $supported is an ordered list of supported language tags (e.g. ['en', 'fr', 'bn-BD']).
     * - Parses $accept for language ranges and optional q-values (e.g. "da, en-gb;q=0.8, en;q=0.7").
     * - Ignores entries with q=0.
     * - Supports wildcard '*' and prefix matches: exact match, "en" ↔ "en-US" prefix matching.
     * - Chooses the first supported language that matches the highest-preference client range.
     * - If $accept is empty or no range matches, returns the first entry from $supported.
     *
     * @param string[] $supported Ordered list of supported language tags.
     * @param string $accept Raw Accept-Language header value.
     * @return string Selected language tag from $supported.
     */
    public static function negotiate(array $supported, string $accept): string
    {
        if ($accept === '') {                       // header missing → first config entry
            return $supported[0];
        }

        /* --- 1. parse “da, en-gb;q=0.8, en;q=0.7” → [['da',1],['en-gb',0.8]…] ---- */
        $parts = [];
        foreach (explode(',', $accept) as $seg) {
            [$tag, $params] = array_map('trim', explode(';', $seg, 2) + [1 => '']);
            $q = (float)(preg_match('/q=([\d.]+)/', $params, $m) ? $m[1] : 1);
            if ($q == 0.0) {
                continue;
            }            // “not acceptable” shortcut
            $parts[] = [strtolower($tag), $q];
        }
        usort($parts, fn ($a, $b) => $b[1] <=> $a[1]); // highest-q first

        /* --- 2. best-match against supported list ------------------------------ */
        foreach ($parts as [$pref]) {
            foreach ($supported as $lang) {
                $low = strtolower($lang);
                if (
                    $pref === '*' ||          // wildcard
                    $pref === $low ||          // exact
                    str_starts_with($pref, $low . '-') ||          // “en-US” vs “en”
                    str_starts_with($low, $pref . '-')                 // “en” vs “en-US”
                ) {
                    return $lang;                          // first hit wins
                }
            }
        }
        return $supported[0];                              // nothing matched – fallback
    }
}
