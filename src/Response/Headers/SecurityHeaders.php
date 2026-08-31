<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Response\Response;

/** Static helpers to attach common security headers without process-global request state. */
final class SecurityHeaders
{
    private const array CORP_VALUES = [
        'same-origin' => true,
        'same-site' => true,
        'cross-origin' => true,
    ];

    private const array REFERRER_VALUES = [
        'no-referrer' => true,
        'no-referrer-when-downgrade' => true,
        'origin' => true,
        'origin-when-cross-origin' => true,
        'same-origin' => true,
        'strict-origin' => true,
        'strict-origin-when-cross-origin' => true,
        'unsafe-url' => true,
    ];

    private const array XFO_VALUES = [
        'DENY' => true,
        'SAMEORIGIN' => true,
    ];

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
        $referrer = strtolower(trim($referrer));
        if (!isset(self::REFERRER_VALUES[$referrer])) {
            throw new \InvalidArgumentException('Unsupported Referrer-Policy value.');
        }

        $xfo = strtoupper(trim($xfo));
        if (!isset(self::XFO_VALUES[$xfo])) {
            throw new \InvalidArgumentException('X-Frame-Options must be DENY or SAMEORIGIN.');
        }

        if ($corp !== null && $corp !== '') {
            $corp = strtolower(trim($corp));
            if (!isset(self::CORP_VALUES[$corp])) {
                throw new \InvalidArgumentException(
                    'Cross-Origin-Resource-Policy must be same-origin, same-site, or cross-origin.',
                );
            }
        }

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
