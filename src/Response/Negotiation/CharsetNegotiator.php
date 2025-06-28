<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

/**
 * Chooses the best charset from **Accept-Charset**.
 *
 * RFC 7231 §5.3.3 — if the header is absent, UTF-8 wins by convention
 * (browsers still default to ISO-8859-1 but modern APIs favour UTF-8).
 */
final class CharsetNegotiator
{
    /**
     * @param string[] $supported e.g. ['utf-8','iso-8859-1']
     * @return string|null
     */
    public static function choose(array $supported, string $acceptCharset): ?string
    {
        if ($acceptCharset === '') {
            return $supported[0] ?? null;
        }

        $cands = [];
        foreach (explode(',', $acceptCharset) as $seg) {
            $seg = trim($seg);
            $q   = 1.0;
            if (str_contains($seg, ';')) {
                [$seg, $param] = explode(';', $seg, 2);
                if (preg_match('/q=([\d.]+)/', $param, $m)) {
                    $q = (float)$m[1];
                }
            }
            $cands[] = [strtolower($seg), $q];
        }
        usort($cands, fn ($a, $b) => $b[1] <=> $a[1]);

        foreach ($cands as [$ch]) {
            if ($ch === '*') {
                return $supported[0] ?? null;
            }
            foreach ($supported as $sup) {
                if (strcasecmp($sup, $ch) === 0) {
                    return $sup;
                }
            }
        }
        return null;
    }
}
