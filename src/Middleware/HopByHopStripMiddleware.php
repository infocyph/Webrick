<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Removes hop-by-hop headers when Webrick acts as a *proxy* or
 * gateway so they are not forwarded downstream (RFC 9110 §7.6).
 */
final readonly class HopByHopStripMiddleware
{
    /** Hop-by-hop header names (lower-case) */
    private const HOP_BY_HOP = [
        'connection','keep-alive','proxy-authenticate','proxy-authorization',
        'te','trailers','transfer-encoding','upgrade'
    ];

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ---- strip from request --------------------------------- */
        foreach (self::HOP_BY_HOP as $h) {
            if ($req->hasHeader($h)) {
                $req = $req->withoutHeader($h);
            }
        }

        /* ---- downstream call ------------------------------------ */
        $resp = $next($req);

        /* ---- strip from response -------------------------------- */
        foreach (self::HOP_BY_HOP as $h) {
            if ($resp->hasHeader($h)) {
                $resp = $resp->withoutHeader($h);
            }
        }
        return $resp;
    }
}
