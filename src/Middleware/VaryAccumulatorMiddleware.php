<?php

/**
 * Webrick - Middleware: Vary header accumulator.
 *
 * Aggregates and normalizes Vary header tokens produced across the pipeline.
 * - Callers can register additional request-header tokens the response varies on.
 * - The middleware merges downstream Vary values, registered tokens, and
 *   auto-inferred tokens (e.g., from CORS, content encoding/language).
 * - It canonicalizes tokens to Title-Case, dedupes case-insensitively, and
 *   removes the Vary header entirely if the final token list is empty.
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Middleware that collects and emits a canonical Vary header.
 *
 * Usage:
 * - At call sites, use VaryAccumulatorMiddleware::add($req, 'Accept-Language')
 *   or ::addIf($req, $condition, 'HeaderA', 'HeaderB').
 * - The middleware then merges these with any downstream Vary values and
 *   auto-inferred tokens, producing a stable, deduped, Title-Case header.
 */
final class VaryAccumulatorMiddleware
{
    /**
     * Request attribute key used to carry queued vary tokens.
     *
     * @var string
     */
    private const ATTR = '__vary_tokens';

    /**
     * Merge tokens from downstream Vary header, queued tokens, and auto-inference.
     *
     * Rules:
     * - If downstream already has "Vary: *", leave as-is.
     * - Otherwise, normalize and dedupe tokens across sources.
     * - Auto-infer Accept-Encoding, Accept-Language, and preflight request headers
     *   when applicable (CORS reflection and OPTIONS preflight).
     * - If the final list is empty, remove Vary; else set the canonicalized list.
     *
     * @param Request $req Incoming request.
     * @param Closure $next Next middleware/controller.
     *
     * @return Response The updated response with a canonical Vary header.
     */
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

    /**
     * Queue one or more request-header names that the response will vary on.
     *
     * Accepts plain tokens or comma-separated lists. Values are stored on the
     * request for later merging by the middleware.
     *
     * @param Request       $r          The current request (immutable carrier).
     * @param string        ...$headers One or more header tokens or CSV strings.
     *
     * @return Request A new request instance with tokens queued.
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
     * Conditionally queue vary tokens, avoiding call-site branching.
     *
     * @param Request $r          The current request.
     * @param bool    $when       Whether to add the tokens.
     * @param string  ...$headers Header tokens or CSV strings to queue when $when is true.
     *
     * @return Request The original request or a new instance with tokens queued.
     */
    public static function addIf(Request $r, bool $when, string ...$headers): Request
    {
        return $when ? self::add($r, ...$headers) : $r;
    }

    /**
     * TEST HELPER: Clear any queued vary tokens on the request.
     *
     * @param Request $r The current request.
     *
     * @return Request A new request with an empty token list.
     */
    public static function clear(Request $r): Request
    {
        return $r->withAttribute(self::ATTR, []);
    }

    /**
     * TEST HELPER: Inspect queued tokens on the request.
     *
     * @param Request $r           The current request.
     * @param bool    $normalized  When true, return canonical Title-Case tokens with dedupe.
     *
     * @return array<int,string> Token list (possibly normalized).
     */
    public static function peek(Request $r, bool $normalized = true): array
    {
        $pending = $r->getAttribute(self::ATTR);
        if (!is_array($pending) || $pending === []) {
            return [];
        }
        return $normalized ? self::normalize($pending) : $pending;
    }

    /**
     * Canonicalize a header name to Title-Case (e.g., "accept-encoding" → "Accept-Encoding").
     *
     * @param string $t Raw header token.
     *
     * @return string Canonical Title-Case token or empty string if input is blank.
     */
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

    /**
     * Check whether the Vary line contains a bare star (*).
     *
     * @param string $line Vary header value.
     *
     * @return bool True if "*" appears as a token; false otherwise.
     */
    private static function hasStar(string $line): bool
    {
        return array_any(self::splitTokens($line), fn ($t) => $t === '*');
    }

    /**
     * Merge two token lists, preserving base order and de-duplicating.
     *
     * @param array<int,string> $base  Existing canonical tokens.
     * @param array<int,string> $extra Additional canonical tokens to append if missing.
     *
     * @return array<int,string> Merged token list.
     */
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

    /**
     * Normalize tokens by canonicalizing to Title-Case and de-duplicating case-insensitively.
     *
     * @param array<int,string> $tokens Input tokens.
     *
     * @return array<int,string> Canonicalized, deduped tokens.
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

    /* ───────────────────────── helpers ───────────────────────── */

    /**
     * Split a comma-separated header list into trimmed tokens.
     *
     * @param string $line Raw header line string.
     *
     * @return array<int,string> Token list (empty when $line is empty).
     */
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
}
