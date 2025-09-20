<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

final class CharsetNegotiator
{
    /**
     * Choose the best matching charset from an Accept-Charset header.
     *
     * Behaviour:
     *  - $supported is an ordered list of server-supported charset names.
     *  - Parses $acceptCharset for tokens and optional q-values (0.000 - 1.000).
     *  - Ignores entries with q=0 and respects client q-weighting and order.
     *  - Treats '*' as a wildcard that yields the server's preferred charset.
     *  - Returns the matching entry from $supported preserving its original casing,
     *    or null if no supported charset is acceptable.
     *
     * @param string[] $supported Server-supported charset names (preference order)
     * @param string $acceptCharset Raw Accept-Charset header value
     * @return string|null Best matching supported charset, or null if none match
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
        usort($cands, fn ($a, $b) => ($b['q'] <=> $a['q']) ?: ($a['i'] <=> $b['i']));

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

    /**
     * Canonicalize a charset token for comparison.
     *
     * - Lowercases the token and normalizes common aliases (e.g. "utf8" -> "utf-8").
     *
     * @param string $x Input charset token
     * @return string Canonicalized charset token for matching
     */
    private static function canon(string $x): string
    {
        $x = strtolower($x);
        return $x === 'utf8' ? 'utf-8' : $x;
    }
}
