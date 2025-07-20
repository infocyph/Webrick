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
     * @param string[]      $supported ISO tags e.g. ['en', 'fr', 'bn-BD']
     * @param string        $accept    Raw Accept-Language header
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
            $q = (float) (preg_match('/q=([\d.]+)/', $params, $m) ? $m[1] : 1);
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
                    $pref === '*'                          ||          // wildcard
                    $pref === $low                         ||          // exact
                    str_starts_with($pref, $low . '-')     ||          // “en-US” vs “en”
                    str_starts_with($low, $pref . '-')                 // “en” vs “en-US”
                ) {
                    return $lang;                          // first hit wins
                }
            }
        }
        return $supported[0];                              // nothing matched – fallback
    }

    /** Build Content-Language + Vary header pair. */
    public static function headers(string $chosen): array
    {
        return [
            ['Content-Language', $chosen],
            ['Vary',             'Accept-Language'],
        ];
    }
}
