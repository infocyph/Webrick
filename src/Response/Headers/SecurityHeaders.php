<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Response\Response;

/** Static helpers to attach common security headers without process-global request state. */
final class SecurityHeaders
{
    private function __construct() {}

    public static function hsts(Response $r, bool $includeSub = true): Response
    {
        $val = 'max-age=31536000' . ($includeSub ? '; includeSubDomains' : '');

        return self::setIfAbsent($r, 'Strict-Transport-Security', $val);
    }

    public static function tight(
        Response $r,
        bool $hsts = true,
        bool $includeSubs = true,
        bool $coop = false,
        bool $coep = false,
        ?string $corp = null,
        bool $oac = false,
        string $referrer = 'no-referrer-when-downgrade',
        string $xfo = 'SAMEORIGIN',
        string $permissions = 'camera=(), geolocation=(), microphone=()',
        bool $secureRequest = false,
    ): Response {
        $r = self::setIfAbsent($r, 'X-Content-Type-Options', 'nosniff');
        $r = self::setIfAbsent($r, 'X-Frame-Options', $xfo);
        $r = self::setIfAbsent($r, 'Referrer-Policy', $referrer);
        $r = self::setIfAbsent($r, 'Permissions-Policy', $permissions);

        if ($coop) {
            $r = self::setIfAbsent($r, 'Cross-Origin-Opener-Policy', 'same-origin');
        }
        if ($coep) {
            $r = self::setIfAbsent($r, 'Cross-Origin-Embedder-Policy', 'require-corp');
        }
        if ($corp !== null && $corp !== '') {
            $r = self::setIfAbsent($r, 'Cross-Origin-Resource-Policy', $corp);
        }
        if ($oac) {
            $r = self::setIfAbsent($r, 'Origin-Agent-Cluster', '?1');
        }

        return $hsts && $secureRequest ? self::hsts($r, $includeSubs) : $r;
    }

    private static function setIfAbsent(Response $r, string $name, string $value): Response
    {
        return $r->hasHeader($name) ? $r : $r->withHeader($name, $value);
    }
}
