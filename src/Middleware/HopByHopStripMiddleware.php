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
    private const HOP_BY_HOP = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailers',
        'transfer-encoding',
        'upgrade',
    ];

    public function __invoke(Request $req, Closure $next): Response
    {
        // also strip dynamic tokens mentioned in "Connection"
        $reqTokens = $this->parseConnectionTokens($req->getHeaderLine('Connection'));
        foreach (array_unique(array_merge(self::HOP_BY_HOP, $reqTokens)) as $h) {
            if ($req->hasHeader($h)) {
                $req = $req->withoutHeader($h);
            }
        }

        $resp = $next($req);

        $resTokens = $this->parseConnectionTokens($resp->getHeaderLine('Connection'));
        foreach (array_unique(array_merge(self::HOP_BY_HOP, $resTokens)) as $h) {
            if ($resp->hasHeader($h)) {
                $resp = $resp->withoutHeader($h);
            }
        }

        return $resp;
    }

    private function parseConnectionTokens(string $line): array
    {
        if ($line === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $line) as $t) {
            $t = strtolower(trim($t));
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }
}
