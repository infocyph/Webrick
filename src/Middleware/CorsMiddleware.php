<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Definition\Attribute\Cors; // Import the new Cors attribute

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
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        // Start with the globally configured policy
        $policy = [
            'origins'        => $this->origins,
            'methods'        => $this->methods,
            'headers'        => $this->headers,
            'maxAgeSeconds'  => $this->maxAgeSeconds,
            'allowCredentials' => $this->allowCredentials,
        ];

        // Check for a route-specific policy and merge it
        /** @var Cors|null $routePolicy */
        $routePolicy = $req->getAttribute('cors_policy');
        if ($routePolicy instanceof Cors) {
            $policy['origins'] = $routePolicy->origins;
            $policy['methods'] = $routePolicy->methods ?? $policy['methods'];
            $policy['headers'] = $routePolicy->headers ?? $policy['headers'];
            $policy['maxAgeSeconds'] = $routePolicy->maxAgeSeconds ?? $policy['maxAgeSeconds'];
            $policy['allowCredentials'] = $routePolicy->allowCredentials ?? $policy['allowCredentials'];
        }

        $origin = $req->getHeaderLine('Origin');
        $allowed = $this->isAllowedOrigin($origin, $policy['origins']) ? $origin : null;

        /* ---------- Pre-flight (OPTIONS) --------------------------- */
        if ($req->getMethod() === 'OPTIONS') {
            return $this->applyHeaders(
                new Response(204, new Stream('')),
                $allowed,
                $policy // Pass the final policy
            );
        }

        /* ---------- Normal request -------------------------------- */
        $resp = $next($req);
        return $this->applyHeaders($resp, $allowed, $policy); // Pass the final policy
    }

    /* -------------------------------------------------------------- */
    private function applyHeaders(Response $r, ?string $origin, array $policy): Response
    {
        $r = $r
            ->withHeader('Access-Control-Allow-Methods', $policy['methods'])
            ->withHeader('Access-Control-Allow-Headers', $policy['headers'])
            ->withHeader('Access-Control-Max-Age', (string) $policy['maxAgeSeconds']);

        if ($policy['allowCredentials']) {
            $r = $r->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $r = $r->withHeader(
            'Access-Control-Allow-Origin',
            $origin ?? ($policy['origins'] === ['*'] ? '*' : '')
        );

        if ($origin && $policy['allowCredentials']) {
            // When credentials are enabled, wildcard is illegal – reflect.
            $r = $r->withHeader('Access-Control-Allow-Origin', $origin);
        }

        // Safari quirk – always echo Vary when Origin is dynamic
        if ($policy['origins'] !== ['*']) {
            $r = $r->withHeader('Vary', 'Origin');
        }
        return $r;
    }

    private function isAllowedOrigin(string $origin, array $allowedOrigins): bool
    {
        return $origin === ''
            || $allowedOrigins === ['*']
            || in_array($origin, $allowedOrigins, true);
    }
}
