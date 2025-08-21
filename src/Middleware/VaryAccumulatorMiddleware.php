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

    /**
     * TEST HELPER: clear any queued vary tokens on the request.
     */
    public static function clear(Request $r): Request
    {
        return $r->withAttribute(self::ATTR, []);
    }

    /**
     * TEST HELPER: inspect queued tokens on the request.
     * @return string[] tokens (normalized Title-Case by default)
     */
    public static function peek(Request $r, bool $normalized = true): array
    {
        $pending = $r->getAttribute(self::ATTR);
        if (!is_array($pending) || $pending === []) {
            return [];
        }
        return $normalized ? self::normalize($pending) : $pending;
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

        // ── Auto-infer from final response ─────────────────────────

        // Encoding implies Accept-Encoding variance
        if ($resp->hasHeader('Content-Encoding')) {
            $tokens = self::merge($tokens, ['Accept-Encoding']);
        }

        // If we declare a content language, we likely varied by Accept-Language
        if ($resp->hasHeader('Content-Language')) {
            $tokens = self::merge($tokens, ['Accept-Language']);
        }

        // CORS: if ACAO is reflected (not "*"), vary by Origin (and preflight headers for OPTIONS)
        $acao = trim($resp->getHeaderLine('Access-Control-Allow-Origin'));
        if ($acao !== '' && $acao !== '*') {
            $tokens = self::merge($tokens, ['Origin']);

            // Preflight: vary on Access-Control-Request-Method/Headers when present
            if (strtoupper($req->getMethod()) === 'OPTIONS') {
                if ($req->getHeaderLine('Access-Control-Request-Method') !== '') {
                    $tokens = self::merge($tokens, ['Access-Control-Request-Method']);
                }
                if ($req->getHeaderLine('Access-Control-Request-Headers') !== '') {
                    $tokens = self::merge($tokens, ['Access-Control-Request-Headers']);
                }
            }
        }

        if ($tokens === []) {
            return $resp->getHeaderLine('Vary') === '' ? $resp : $resp->withoutHeader('Vary');
        }

        $final = implode(', ', $tokens);
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
        return array_any(self::splitTokens($line), fn ($t) => $t === '*');
    }

    /**
     * Normalize tokens: case-insensitive dedupe, canonical Title-Case form.
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
        $parts = array_map(
            static fn (string $p) => $p === '' ? '' : ucfirst(strtolower($p)),
            explode('-', $t),
        );
        return implode('-', $parts);
    }
}
