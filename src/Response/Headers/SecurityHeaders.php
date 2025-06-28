<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Response\Response;

/**
 * Static helpers to attach common security headers quickly.
 *
 * ```php
 * $resp = SecurityHeaders::tight($resp);
 * ```
 */
final class SecurityHeaders
{
    private function __construct() {}

    /** Opinionated secure defaults (can be interposed via withHeader). */
    public static function tight(Response $r): Response
    {
        return $r
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'no-referrer-when-downgrade')
            ->withHeader('X-XSS-Protection', '0');
    }

    /** Apply basic HSTS (1 year). */
    public static function hsts(Response $r, bool $includeSub = true): Response
    {
        $val = 'max-age=31536000' . ($includeSub ? '; includeSubDomains' : '');
        return $r->withHeader('Strict-Transport-Security', $val);
    }
}
