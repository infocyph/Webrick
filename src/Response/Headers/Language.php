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
        if ($accept === '') {
            return $supported[0];
        }

        $prefs = array_map('trim', explode(',', strtolower($accept)));

        foreach ($prefs as $pref) {
            foreach ($supported as $lang) {
                $low = strtolower($lang);
                if ($low === $pref || str_starts_with($pref, $low . '-')) {
                    return $lang;
                }
            }
        }
        return $supported[0]; // fallback
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
