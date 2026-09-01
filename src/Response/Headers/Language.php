<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Support\HttpUtils;

/** Choose the best language for a response and build related headers. */
final class Language
{
    /** @return array<int,array{string,string}> */
    public static function headers(string $chosen): array
    {
        return [
            ['Content-Language', $chosen],
            ['Vary', 'Accept-Language'],
        ];
    }

    /** @param string[] $supported */
    public static function negotiate(array $supported, string $accept): string
    {
        if ($supported === []) {
            throw new \InvalidArgumentException('At least one supported language is required.');
        }
        if ($accept === '') {
            return $supported[0];
        }

        foreach (self::preferences($accept) as $preference) {
            $pref = $preference['tag'];
            foreach ($supported as $lang) {
                if (self::matches($pref, $lang)) {
                    return $lang;
                }
            }
        }

        return $supported[0];
    }

    private static function matches(string $preference, string $language): bool
    {
        $language = strtolower($language);

        return $preference === '*'
            || $preference === $language
            || str_starts_with($preference, $language . '-')
            || str_starts_with($language, $preference . '-');
    }

    /** @return list<array{tag:string,q:float,order:int}> */
    private static function preferences(string $accept): array
    {
        $parts = [];
        foreach (explode(',', $accept) as $order => $segment) {
            $tokens = array_map(trim(...), explode(';', $segment));
            $tag = strtolower(array_shift($tokens));
            if ($tag === '') {
                continue;
            }

            $q = 1.0;
            foreach ($tokens as $parameter) {
                if (preg_match('/^q\s*=\s*(.*)$/i', $parameter, $matches) !== 1) {
                    continue;
                }
                $q = HttpUtils::parseQValue($matches[1]) ?? 0.0;

                break;
            }
            if ($q <= 0.0) {
                continue;
            }
            $parts[] = ['tag' => $tag, 'q' => $q, 'order' => $order];
        }

        usort(
            $parts,
            static fn(array $a, array $b): int => $b['q'] <=> $a['q'] ?: $a['order'] <=> $b['order'],
        );

        return $parts;
    }
}
