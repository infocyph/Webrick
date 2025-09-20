<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Response\Response;

/**
 * Static helpers to attach common security headers without clobbering
 * values that may have been set earlier (reverse proxy, controller, etc.).
 *
 * Example:
 *   $resp = SecurityHeaders::tight($resp, hsts: true, coop: true, coep: false);
 */
final class SecurityHeaders
{
    /**
     * Prevent instantiation — this class exposes only static helper methods.
     */
    private function __construct()
    {
    }

    /**
     * Apply an opinionated set of security headers without overriding existing values.
     *
     * Behaviour:
     *  - Ensures X-Content-Type-Options, X-Frame-Options, Referrer-Policy and Permissions-Policy
     *    are present (set only if absent).
     *  - Optionally adds COOP/COEP/CORP/OAC headers when explicitly requested.
     *  - Optionally applies HSTS when $hsts is true and the request looks HTTPS-like.
     *
     * Parameters are tunable to avoid breaking embeds by default; use the boolean flags to
     * opt-in to stronger isolation policies.
     *
     * @param Response $r Response to modify (immutable API; returned value may be a new instance)
     * @param bool $hsts Whether to apply Strict-Transport-Security when the request is HTTPS-like
     * @param bool $includeSubs Whether to include subdomains in the HSTS header
     * @param bool $coop If true, set Cross-Origin-Opener-Policy: same-origin
     * @param bool $coep If true, set Cross-Origin-Embedder-Policy: require-corp
     * @param string|null $corp If non-null/non-empty, set Cross-Origin-Resource-Policy to this value
     * @param bool $oac If true, set Origin-Agent-Cluster: ?1
     * @param string $referrer Referrer-Policy value to set if absent
     * @param string $xfo X-Frame-Options value to set if absent
     * @param string $permissions Permissions-Policy value to set if absent
     * @return Response Response instance with the applied headers
     */
    public static function tight(
        Response $r,
        bool $hsts = true,
        bool $includeSubs = true,
        // Optional extra isolation (opt-in: can break third-party embeds)
        bool $coop = false,                        // Cross-Origin-Opener-Policy: same-origin
        bool $coep = false,                        // Cross-Origin-Embedder-Policy: require-corp
        ?string $corp = null,                      // Cross-Origin-Resource-Policy (e.g., 'same-origin' | 'same-site')
        bool $oac = false,                         // Origin-Agent-Cluster: ?1
        // Tunables (keep your current defaults)
        string $referrer = 'no-referrer-when-downgrade',
        string $xfo = 'SAMEORIGIN',
        string $permissions = "camera=(), geolocation=(), microphone=()",
    ): Response {
        // core, non-clobbering defaults
        $r = self::setIfAbsent($r, 'X-Content-Type-Options', 'nosniff');
        $r = self::setIfAbsent($r, 'X-Frame-Options', $xfo);
        $r = self::setIfAbsent($r, 'Referrer-Policy', $referrer);
        $r = self::setIfAbsent($r, 'Permissions-Policy', $permissions);

        // optional isolation headers (only if requested)
        if ($coop) {
            $r = self::setIfAbsent($r, 'Cross-Origin-Opener-Policy', 'same-origin');
        }
        if ($coep) {
            $r = self::setIfAbsent($r, 'Cross-Origin-Embedder-Policy', 'require-corp');
        }
        if ($corp !== null && $corp !== '') {
            $r = self::setIfAbsent($r, 'Cross-Origin-Resource-Policy', $corp); // e.g., 'same-origin' or 'same-site'
        }
        if ($oac) {
            $r = self::setIfAbsent($r, 'Origin-Agent-Cluster', '?1');
        }

        // HSTS (non-clobbering)
        $httpsish = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTPS'] ?? '') === '1')
            || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return $hsts && $httpsish ? self::hsts($r, $includeSubs) : $r;
    }

    /**
     * Apply Strict-Transport-Security if the header is not already present.
     *
     * Produces the header value "max-age=31536000" (1 year) and appends
     * "; includeSubDomains" when $includeSub is true.
     *
     * @param Response $r Response to modify (immutable API; returned value may be a new instance)
     * @param bool $includeSub Whether to include subdomains in the HSTS directive
     * @return Response Response instance with Strict-Transport-Security set if absent
     */
    public static function hsts(Response $r, bool $includeSub = true): Response
    {
        $val = 'max-age=31536000' . ($includeSub ? '; includeSubDomains' : '');
        return self::setIfAbsent($r, 'Strict-Transport-Security', $val);
    }

    /* ——— internal utility ——— */

    /**
     * Set a header on the response only if it is not already present.
     *
     * This respects upstream/clobbering semantics: if the given header name
     * already exists on $r, the original response is returned unmodified.
     *
     * @param Response $r Response to check and possibly modify
     * @param string $name Header name to set (case-insensitive per PSR/http)
     * @param string $value Header value to set when absent
     * @return Response The response with the header set, or the original response if present
     */
    private static function setIfAbsent(Response $r, string $name, string $value): Response
    {
        return $r->hasHeader($name) ? $r : $r->withHeader($name, $value);
    }
}
