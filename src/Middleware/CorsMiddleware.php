<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Infocyph\Webrick\Request\Request;

/**
 * Very small CORS layer (sufficient for most APIs).
 *
 * • Handles pre-flight 100 % in-memory (204 No Content).
 * • Adds the CORS headers to *every* response.
 */
final readonly class CorsMiddleware
{
    /** @param string[] $origins   (‘*’ ⇒ anything) */
    public function __construct(
        private array   $origins        = ['*'],
        private string  $methods        = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        private string  $headers        = 'Content-Type, Authorization',
        private int     $maxAgeSeconds  = 3600,
        private bool    $allowCredentials = true,
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        $origin = $req->getHeaderLine('Origin');
        $allowed = $this->isAllowedOrigin($origin) ? $origin : null;

        /* ---------- Pre-flight (OPTIONS) --------------------------- */
        if ($req->getMethod() === 'OPTIONS') {
            return $this->applyHeaders(
                new Response(204, new Stream('')),
                $allowed
            );
        }

        /* ---------- Normal request -------------------------------- */
        $resp = $next($req);
        return $this->applyHeaders($resp, $allowed);
    }

    /* -------------------------------------------------------------- */
    private function applyHeaders(Response $r, ?string $origin): Response
    {
        $r = $r
            ->withHeader('Access-Control-Allow-Methods',  $this->methods)
            ->withHeader('Access-Control-Allow-Headers',  $this->headers)
            ->withHeader('Access-Control-Max-Age',        (string) $this->maxAgeSeconds);

        if ($this->allowCredentials) {
            $r = $r->withHeader('Access-Control-Allow-Credentials', 'true');
        }
        $r = $r->withHeader(
            'Access-Control-Allow-Origin',
            $origin ?? ($this->origins === ['*'] ? '*' : '')
        );

        if ($origin && $this->allowCredentials) {
            // When credentials are enabled, wildcard is illegal – reflect.
            $r = $r->withHeader('Access-Control-Allow-Origin', $origin);
        }

        // Safari quirk – always echo Vary when Origin is dynamic
        if ($this->origins !== ['*']) {
            $r = $r->withHeader('Vary', 'Origin');
        }
        return $r;
    }

    private function isAllowedOrigin(string $origin): bool
    {
        return $origin === ''
            || $this->origins === ['*']
            || in_array($origin, $this->origins, true);
    }
}
