<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Request;

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
    /**
     * Size of the raw random string (in **bytes**).
     * 32 bytes → 64-hex-char plain token, 128-char masked token.
     */
    private const TOKEN_BYTES = 32;

    /* -----------------------------------------------------------------
       1)  Stored token helpers  (session-backed)
       ---------------------------------------------------------------- */

    /** Return the plain token (hex-encoded), create if absent. */
    public static function token(): string
    {
        return $_SESSION['_token'] ??= bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Return a masked token:
     *   mask (64 hex) + hash_hmac('sha256', mask·token, '')
     * Length = 64 + 64 = 128 chars.
     */
    public static function maskedToken(): string
    {
        $mask = bin2hex(random_bytes(self::TOKEN_BYTES));

        // Empty key ⇒ pure SHA-256, but `hash_hmac` is constant-time.
        return $mask . hash_hmac('sha256', $mask . self::token(), '');
    }

    /* -----------------------------------------------------------------
       2)  Matching helper
       ---------------------------------------------------------------- */

    public static function matches(Request $req): bool
    {
        $sent   = self::extractFromRequest($req);
        $stored = $_SESSION['_token'] ?? null;

        if (!$sent || !$stored) {
            return false;
        }

        $hexLen = self::TOKEN_BYTES * 2; // 64
        $maskedLen = $hexLen * 2;        // 128

        // Masked token → unmask
        if (\strlen($sent) === $maskedLen && \strlen($stored) === $hexLen) {
            $mask   = \substr($sent, 0, $hexLen);
            $hashed = \substr($sent, $hexLen);

            return \hash_equals(
                $hashed,
                \hash_hmac('sha256', $mask . $stored, '')
            );
        }

        // Plain comparison (fallback – should be rare)
        return \hash_equals($stored, $sent);
    }

    /* -----------------------------------------------------------------
       3)  Internals
       ---------------------------------------------------------------- */

    /**
     * Look for token in header, body, query, cookie
     * (Laravel priority order).
     */
    private static function extractFromRequest(Request $req): ?string
    {
        // 1) Explicit header
        $hdr = $req->getHeaderLine('X-CSRF-TOKEN')
            ?: $req->getHeaderLine('X-XSRF-TOKEN');
        if ($hdr !== '') {
            return $hdr;
        }

        // 2) Form field wins over query param
        $body = $req->getParsedBody();
        if (\is_array($body) && ($body['_token'] ?? '') !== '') {
            return (string) $body['_token'];
        }

        \parse_str($req->getUri()->getQuery(), $q);
        if (($q['_token'] ?? '') !== '') {
            return (string) $q['_token'];
        }

        // 3) Cookie
        $cookie = $req->getCookieParams()['XSRF-TOKEN'] ?? null;

        return $cookie !== '' ? (string) $cookie : null;
    }

    /** Library is static-only. */
    private function __construct()
    {
    }
}
