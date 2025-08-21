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
    private function __construct()
    {
    }

    /**
     * Opinionated secure defaults (set-if-absent). Optionally adds HSTS.
     * Extra hardening (COOP/COEP/CORP/OAC) is opt-in to avoid breaking embeds.
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

    /** Apply Strict-Transport-Security (defaults to 1 year), set-if-absent. */
    public static function hsts(Response $r, bool $includeSub = true): Response
    {
        $val = 'max-age=31536000' . ($includeSub ? '; includeSubDomains' : '');
        return self::setIfAbsent($r, 'Strict-Transport-Security', $val);
    }

    /* ——— internal utility ——— */

    private static function setIfAbsent(Response $r, string $name, string $value): Response
    {
        return $r->hasHeader($name) ? $r : $r->withHeader($name, $value);
    }
}
