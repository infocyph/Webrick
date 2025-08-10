<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Response\Response;

/**
 * Static helpers to attach common security headers quickly.
 *
 * Example:
 *     $resp = SecurityHeaders::tight($resp, hsts: true);
 */
final class SecurityHeaders
{
    private function __construct() {}

    /** Opinionated secure defaults. Optionally adds HSTS. */
    public static function tight(
        Response $r,
        bool $hsts = true,
        bool $includeSubs = true,
    ): Response {
        $r = $r
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'no-referrer-when-downgrade')
            ->withHeader('Permissions-Policy', "camera=(), geolocation=(), microphone=()");

        return $hsts ? self::hsts($r, $includeSubs) : $r;
    }

    /** Apply Strict-Transport-Security (defaults to 1 year). */
    public static function hsts(Response $r, bool $includeSub = true): Response
    {
        $val = 'max-age=31536000' . ($includeSub ? '; includeSubDomains' : '');
        return $r->withHeader('Strict-Transport-Security', $val);
    }
}
