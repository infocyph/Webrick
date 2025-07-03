<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Csrf – tiny helper that mirrors Laravel 12 semantics
 * ----------------------------------------------------
 *   // on first request
 *   $cookie = Csrf::maskedToken();              // send as "XSRF-TOKEN" cookie
 *
 *   // later, on POST/PUT/…
 *   if (!Csrf::matches($request)) {
 *       throw new \RuntimeException('419 CSRF mismatch');
 *   }
 */
final class Csrf
{
    /* -----------------------------------------------------------------
       1)  Stored token helpers  (session-backed)
       ----------------------------------------------------------------- */

    /** Return plain 40-char token, create if absent. */
    public static function token(): string
    {
        return $_SESSION['_token'] ??= bin2hex(random_bytes(20)); // 40 hex chars
    }

    /** Generate / return masked 80-char token (mask + sha1(mask·token)). */
    public static function maskedToken(): string
    {
        $mask = bin2hex(random_bytes(20));                        // 40
        return $mask . sha1($mask . self::token());               // 40 + 40
    }

    /* -----------------------------------------------------------------
       2)  Matching helper
       ----------------------------------------------------------------- */

    public static function matches(ServerRequestInterface $req): bool
    {
        $sent = self::extractFromRequest($req);
        $stored = $_SESSION['_token'] ?? null;

        if (!$sent || !$stored) {
            return false;
        }

        // Masked (80) → unmask against stored (40)
        if (strlen($sent) === 80 && strlen($stored) === 40) {
            $mask   = substr($sent, 0, 40);
            $hashed = substr($sent, 40);                    // SHA-1(mask·token)
            return hash_equals($hashed, sha1($mask . $stored));
        }

        // Plain comparison
        return hash_equals($stored, $sent);
    }

    /* -----------------------------------------------------------------
       3)  Internals
       ----------------------------------------------------------------- */

    /** Look for token in header, body, query, cookie (Laravel priority). */
    private static function extractFromRequest(ServerRequestInterface $req): ?string
    {
        // 1. Explicit header
        $hdr = $req->getHeaderLine('X-CSRF-TOKEN') ?: $req->getHeaderLine('X-XSRF-TOKEN');
        if ($hdr !== '') {
            return $hdr;
        }

        // 2. Form field (_token) wins over query param
        $body = $req->getParsedBody();
        if (is_array($body) && isset($body['_token']) && $body['_token'] !== '') {
            return (string) $body['_token'];
        }
        parse_str($req->getUri()->getQuery(), $q);
        if (($q['_token'] ?? '') !== '') {
            return (string) $q['_token'];
        }

        // 3. Cookie
        $cookie = $req->getCookieParams()['XSRF-TOKEN'] ?? null;
        return $cookie !== '' ? (string)$cookie : null;
    }

    /** Library is static-only. */
    private function __construct()
    {
    }
}
