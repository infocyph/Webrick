<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Headers\SecurityHeaders;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

final readonly class CorsAndPoliciesMiddleware
{
    /** @param string[] $origins  Allowed origins (['*'] ⇒ wildcard) */
    /** @param string[] $acceptCh Client Hints to request via Accept-CH */
    public function __construct(
        // CORS
        private array  $origins = ['*'],
        private string $methods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        private string $allowHeaders = 'Content-Type, Authorization',
        private string $exposeHeaders = 'Content-Length, Content-Type, ETag, Server-Timing, Location, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset',
        private int    $maxAgeSeconds = 3600,
        private bool   $allowCredentials = true,

        // Security / Policies
        private bool   $hsts = true,
        private bool   $hstsIncludeSubdomains = true,
        private ?string $csp = "default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self';",
        private array  $acceptCh = [
            'Sec-CH-UA',
            'Sec-CH-UA-Mobile',
            'Sec-CH-UA-Platform',
            'Sec-CH-UA-Arch',
            'Sec-CH-UA-Model',
            'Sec-CH-UA-Full-Version',
        ],

        // NEL / Reporting
        private ?string $nelGroup = null,                 // set to enable NEL
        private ?string $nelEndpoint = null,              // absolute URL
        private int     $nelTtlSeconds = 86400,
        private bool    $nelIncludeSubdomains = true,
        private bool    $nelCollectSuccess = false,
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        // Start with global policy, allow route override via #[Cors] attribute.
        $policy = [
            'origins'          => $this->origins,
            'methods'          => $this->methods,
            'allowHeaders'     => $this->allowHeaders,
            'exposeHeaders'    => $this->exposeHeaders,
            'maxAgeSeconds'    => $this->maxAgeSeconds,
            'allowCredentials' => $this->allowCredentials,
        ];

        /** @var Cors|null $route */
        $route = $req->getAttribute('cors_policy');
        if ($route instanceof Cors) {
            $policy['origins']          = $route->origins;
            $policy['methods']          = $route->methods          ?? $policy['methods'];
            $policy['allowHeaders']     = $route->headers          ?? $policy['allowHeaders'];
            $policy['maxAgeSeconds']    = $route->maxAgeSeconds    ?? $policy['maxAgeSeconds'];
            $policy['allowCredentials'] = $route->allowCredentials ?? $policy['allowCredentials'];
            // exposeHeaders stays global (route-level override if you like: extend Cors attr)
        }

        $origin  = $req->getHeaderLine('Origin');
        $allowed = $this->isAllowedOrigin($origin, $policy['origins']) ? $origin : null;

        // If we might reflect an Origin (non-wildcard list) tell the accumulator now.
        if ($policy['origins'] !== ['*']) {
            $req = VaryAccumulatorMiddleware::add($req, 'Origin');
        }

        /* ── Preflight short-circuit ───────────────────────────────── */
        if ($req->getMethod() === 'OPTIONS') {
            $resp = new Response(204, new Stream(''));
            $resp = $this->applyCors($resp, $allowed, $policy, /*preflight*/true);
            $resp = $this->applyPolicies($resp);
            return $resp;
        }

        /* ── Normal request ────────────────────────────────────────── */
        $resp = $next($req);
        $resp = $this->applyCors($resp, $allowed, $policy, /*preflight*/false);
        $resp = $this->applyPolicies($resp);

        return $resp;
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function applyCors(Response $r, ?string $origin, array $p, bool $preflight): Response
    {
        // Always send these
        $r = $r
            ->withHeader('Access-Control-Allow-Methods', $p['methods'])
            ->withHeader('Access-Control-Max-Age', (string)$p['maxAgeSeconds']);

        // Request headers the client is allowed to send on CORS requests
        $r = $r->withHeader('Access-Control-Allow-Headers', $p['allowHeaders']);

        // Which response headers the client is allowed to read
        if ($this->exposeHeaders !== '') {
            $r = $r->withHeader('Access-Control-Expose-Headers', $p['exposeHeaders']);
        }

        if ($p['allowCredentials']) {
            $r = $r->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        // ACAO: reflect the specific Origin when allowed, otherwise wildcard (only if legal)
        $acao = null;

        if ($origin !== null) {
            // specific origin allowed
            $acao = $origin;
        } else {
            // not a listed origin
            if ($p['origins'] === ['*'] && !$p['allowCredentials']) {
                $acao = '*'; // wildcard only when credentials are OFF
            }
        }

        if ($acao !== null && $acao !== '') {
            $r = $r->withHeader('Access-Control-Allow-Origin', $acao);
            // If we reflected, ensure Vary: Origin is present even on preflight
            if ($acao !== '*') {
                $vary = $r->getHeaderLine('Vary');
                $r = $r->withHeader('Vary', $vary === '' ? 'Origin' : $vary . ', Origin');
            }
        }

        return $r;
    }

    private function applyPolicies(Response $r): Response
    {
        // SecurityHeaders (HSTS, COOP/COEP/CORP, Referrer-Policy, etc.)
        $r = SecurityHeaders::tight(
            $r,
            hsts: $this->hsts,
            includeSubs: $this->hstsIncludeSubdomains,
        );

        // CSP if provided
        if ($this->csp !== null && $this->csp !== '') {
            $r = $r->withHeader('Content-Security-Policy', $this->csp);
        }

        // Client Hints: only advertise when actually used (config decides)
        if (!empty($this->acceptCh)) {
            $r = $r->withHeader('Accept-CH', implode(', ', $this->acceptCh));
        }

        // Network Error Logging / Report-To (optional)
        if ($this->nelGroup && $this->nelEndpoint) {
            $nel = [
                'group'               => $this->nelGroup,
                'max_age'             => $this->nelTtlSeconds,
                'include_subdomains'  => $this->nelIncludeSubdomains,
                'success_fraction'    => $this->nelCollectSuccess ? 1.0 : 0.0,
                'failure_fraction'    => 1.0,
            ];
            $reportTo = [
                'group'     => $this->nelGroup,
                'max_age'   => $this->nelTtlSeconds,
                'endpoints' => [['url' => $this->nelEndpoint]],
            ];

            $r = $r
                ->withHeader('NEL', json_encode($nel, JSON_THROW_ON_ERROR))
                ->withHeader('Report-To', json_encode($reportTo, JSON_THROW_ON_ERROR));
        }

        return $r;
    }

    private function isAllowedOrigin(string $origin, array $allowedOrigins): bool
    {
        return $origin === ''                      // non-CORS request
            || $allowedOrigins === ['*']           // wildcard mode
            || in_array($origin, $allowedOrigins, true);
    }
}
