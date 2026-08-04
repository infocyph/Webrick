<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Request;

/**
 * Tiny CSRF helper with masked-token semantics.
 *
 * Example:
 * $cookie = Csrf::maskedToken();
 * if (!Csrf::matches($request)) {
 *     throw new \RuntimeException('419 CSRF mismatch');
 * }
 */
final class Csrf
{
    /**
     * Size of the raw random string (in **bytes**).
     * 32 bytes → 64-hex-char plain token, 128-char masked token.
     */
    private const int TOKEN_BYTES = 32;

    /**
     * Private constructor to prevent instantiation of this class.
     */
    private function __construct() {}

    /**
     * Returns a masked CSRF token (128 hex chars) that contains the following components:
     *   - A random 64-hex-char mask
     *   - The SHA-3-256 HMAC of the concatenation of the mask and the plain token
     *
     * This format provides both a secure and efficient way to verify the token.
     * The empty key argument to `hash_hmac` ensures that the HMAC computation is constant-time.
     *
     * @return string The masked CSRF token.
     */
    public static function maskedToken(): string
    {
        $mask = bin2hex(random_bytes(self::TOKEN_BYTES));

        // Empty key ⇒ pure SHA-256, but `hash_hmac` is constant-time.
        return $mask . hash_hmac('sha3-256', $mask . self::token(), '');
    }

    /**
     * Check if the given CSRF token matches the stored value.
     *
     * The CSRF token is extracted from the request using {@see extractFromRequest}.
     * The extracted token is then compared with the stored value using
     * {@see matchesValue}.
     *
     * @param Request $req The request object to extract the CSRF token from.
     * @return bool True if the token matches, false otherwise.
     */
    public static function matches(Request $req): bool
    {
        return self::matchesValue(self::extractFromRequest($req));
    }

    /**
     * Compares the given CSRF token against the stored value.
     *
     * Handles both plain and masked tokens. If the given token is a masked
     * token, it will be verified using HMAC-SHA3-256. Plain tokens are compared
     * directly.
     *
     * @param ?string $sent The CSRF token to compare.
     * @return bool True if the token matches, false otherwise.
     */
    public static function matchesValue(?string $sent): bool
    {
        $stored = self::sessionToken();
        if (!$sent || !$stored) {
            return false;
        }

        $hexLen = self::TOKEN_BYTES * 2; // 64
        $maskedLen = $hexLen * 2;           // 128

        // Masked token → verify HMAC(mask · stored)
        if (\strlen($sent) === $maskedLen && \strlen($stored) === $hexLen) {
            $mask = \substr($sent, 0, $hexLen);
            $hashed = \substr($sent, $hexLen);

            return \hash_equals(
                $hashed,
                \hash_hmac('sha3-256', $mask . $stored, ''),
            );
        }

        // Plain comparison
        return \hash_equals($stored, $sent);
    }

    /**
     * Retrieves the CSRF token from the session, or generates a new one if it does not exist.
     *
     * @return string The CSRF token as a 64-hex-char string.
     */
    public static function token(): string
    {
        $stored = self::sessionToken();
        if ($stored !== null) {
            return $stored;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $_SESSION['_token'] = $token;

        return $token;
    }

    /**
     * Extract the CSRF token from the request (in this order):
     *   1. Header ('X-CSRF-TOKEN' or 'X-XSRF-TOKEN')
     *   2. Form field ('_token')
     *   3. Query param ('_token')
     *   4. Cookie ('XSRF-TOKEN')
     *
     * @return string|null Token value if found, or null if not.
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
        if (\is_array($body)) {
            $bodyToken = $body['_token'] ?? null;
            if (\is_string($bodyToken) && $bodyToken !== '') {
                return $bodyToken;
            }
        }

        \parse_str($req->getUri()->getQuery(), $q);
        $queryToken = $q['_token'] ?? null;
        if (\is_string($queryToken) && $queryToken !== '') {
            return $queryToken;
        }

        // 3) Cookie
        $cookie = $req->getCookieParams()['XSRF-TOKEN'] ?? null;

        return \is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    private static function sessionToken(): ?string
    {
        $stored = $_SESSION['_token'] ?? null;

        return \is_string($stored) && $stored !== '' ? $stored : null;
    }
}
