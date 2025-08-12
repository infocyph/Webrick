<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class VaryAccumulatorMiddleware
{
    private const ATTR = '__vary_tokens';

    /**
     * Add one or more request-header names that the response will vary on.
     * Accepts plain tokens or comma-separated lists.
     */
    public static function add(Request $r, string ...$headers): Request
    {
        $added = $r->getAttribute(self::ATTR) ?? [];
        foreach ($headers as $h) {
            foreach (self::splitTokens($h) as $tok) {
                if ($tok !== '') {
                    $added[] = $tok;
                }
            }
        }
        return $r->withAttribute(self::ATTR, $added);
    }

    /**
     * Conditional variant to avoid branching at call sites.
     */
    public static function addIf(Request $r, bool $when, string ...$headers): Request
    {
        return $when ? self::add($r, ...$headers) : $r;
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);

        // If downstream already declared Vary: * then nothing to do.
        if (self::hasStar($resp->getHeaderLine('Vary'))) {
            return $resp;
        }

        // Start with downstream tokens (if any)
        $tokens = self::normalize(self::splitTokens($resp->getHeaderLine('Vary')));

        // Merge explicitly registered tokens from the request
        $pending = $req->getAttribute(self::ATTR);
        if (is_array($pending) && $pending !== []) {
            $tokens = self::merge($tokens, self::normalize($pending));
        }

        // Auto-infer common dependencies from the final response
        if ($resp->hasHeader('Content-Encoding')) {
            $tokens = self::merge($tokens, ['Accept-Encoding']);
        }
        if ($resp->hasHeader('Content-Language')) {
            $tokens = self::merge($tokens, ['Accept-Language']);
        }
        // If CORS reflected an origin (i.e., not "*"), vary by Origin
        $acao = trim($resp->getHeaderLine('Access-Control-Allow-Origin'));
        if ($acao !== '' && $acao !== '*') {
            $tokens = self::merge($tokens, ['Origin']);
        }

        if ($tokens === []) {
            // leave header as-is (remove if downstream set empty string)
            return $resp->getHeaderLine('Vary') === '' ? $resp : $resp->withoutHeader('Vary');
        }

        $final = implode(', ', $tokens);
        // Don’t rewrite if identical (keeps identity operations cheap)
        return $final === $resp->getHeaderLine('Vary')
            ? $resp
            : $resp->withHeader('Vary', $final);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** Split comma-separated header list into trimmed tokens. */
    private static function splitTokens(string $line): array
    {
        if ($line === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $line) as $t) {
            $t = trim($t);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Return true if the Vary line contains a bare star (*). */
    private static function hasStar(string $line): bool
    {
        foreach (self::splitTokens($line) as $t) {
            if ($t === '*') {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize tokens: case-insensitive dedupe, canonical Title-Case form.
     * (HTTP field names are case-insensitive; Vary values are field names.)
     */
    private static function normalize(array $tokens): array
    {
        $seen = [];
        $out = [];
        foreach ($tokens as $t) {
            $norm = self::canonical($t);
            $key = strtolower($norm);
            if ($norm !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $norm;
            }
        }
        return $out;
    }

    /** Merge two token lists preserving left-side order, de-duped. */
    private static function merge(array $base, array $extra): array
    {
        $keys = array_fill_keys(array_map('strtolower', $base), true);
        foreach ($extra as $t) {
            $k = strtolower($t);
            if (!isset($keys[$k])) {
                $keys[$k] = true;
                $base[] = $t;
            }
        }
        return $base;
    }

    /** Canonicalize header name to Title-Case (Accept-Encoding, Access-Control-Request-Headers…). */
    private static function canonical(string $t): string
    {
        $t = trim($t);
        if ($t === '') {
            return '';
        }
        // split by hyphen and ucwords each part
        $parts = array_map(
            static fn(string $p) => $p === '' ? '' : ucfirst(strtolower($p)),
            explode('-', $t)
        );
        return implode('-', $parts);
    }
}
