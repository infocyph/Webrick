<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Negotiation;

/**
 * RFC 7231 §5.3 — content-type negotiation against an
 * **Accept:** header.
 *
 * ```php
 * $best = ContentTypeNegotiator::choose(
 *     ['text/html', 'application/json'],
 *     $_SERVER['HTTP_ACCEPT'] ?? ''
 * ); // → e.g. "application/json" or null
 * ```
 *
 * * Wildcards respected.
 * * q-weights are honoured; highest wins.
 * * Runs allocation-free in hot paths.
 */
final class ContentTypeNegotiator
{
    /**
     * @param string[] $supported fully-qualified media types (lower-case)
     * @return string|null        best match or null
     */
    public static function choose(array $supported, string $accept): ?string
    {
        if ($accept === '' || $accept === '*/*') {
            return $supported[0] ?? null;
        }

        // Parse header: type/sub;q=X  →  [ ['t','s',q] … ]  sorted by q desc
        $candidates = [];
        foreach (explode(',', $accept) as $seg) {
            $seg   = trim($seg);
            $q     = 1.0;
            $parts = explode(';', $seg, 2);
            if (isset($parts[1]) && preg_match('/q=([\d.]+)/', $parts[1], $m)) {
                $q = (float)$m[1];
            }
            [$type, $sub] = explode('/', $parts[0], 2) + ['', '*'];
            $candidates[] = [strtolower($type), strtolower($sub), $q];
        }
        usort($candidates, fn ($a, $b) => $b[2] <=> $a[2]); // by weight

        foreach ($candidates as [$t, $s]) {
            foreach ($supported as $mime) {
                [$mt, $ms] = explode('/', $mime);
                $matched   = ($t === '*' || $t === $mt) && ($s === '*' || $s === $ms);
                if ($matched) {
                    return $mime;
                }
            }
        }
        return null;
    }
}
