<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

final class CharsetNegotiator
{
    /**
     * @param string[] $supported e.g. ['utf-8','iso-8859-1']
     * @return string|null        best match or null if none
     */
    public static function choose(array $supported, string $acceptCharset): ?string
    {
        // Canonicalize supported list, but keep original casing for output
        $canon = array_map([self::class, 'canon'], $supported);
        $map = array_combine($canon, $supported) ?: [];

        if ($acceptCharset === '') {
            return $supported[0] ?? null;                 // server default
        }

        $cands = [];
        $i = 0;
        foreach (explode(',', $acceptCharset) as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }

            [$tok, $params] = array_map('trim', explode(';', $seg, 2) + [1 => '']);
            // q= 0..1 (up to 3 decimals); default 1.0
            $q = 1.0;
            if (preg_match('/(?:^|;)\s*q=([01](?:\.\d{1,3})?)/i', $params, $m)) {
                $q = max(0.0, min(1.0, (float)$m[1]));
            }
            if ($q <= 0.0) {
                continue;
            }                      // not acceptable

            $cands[] = ['ch' => self::canon($tok), 'q' => $q, 'i' => $i++];
        }

        // sort by q desc, then keep client order for ties
        usort($cands, fn($a, $b) => ($b['q'] <=> $a['q']) ?: ($a['i'] <=> $b['i']));

        foreach ($cands as $c) {
            if ($c['ch'] === '*') {
                return $supported[0] ?? null;             // server preference wins
            }
            if (isset($map[$c['ch']])) {
                return $map[$c['ch']];                    // return original casing
            }
        }
        return null;
    }

    private static function canon(string $x): string
    {
        $x = strtolower($x);
        return $x === 'utf8' ? 'utf-8' : $x;
    }
}
