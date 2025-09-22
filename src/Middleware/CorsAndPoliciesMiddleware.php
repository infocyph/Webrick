<?php

/**
 * Webrick - CORS and security policies middleware.
 *
 * Applies CORS headers for preflight and simple/actual requests, with support for
 * route-level overrides via the #[Cors] attribute. Also attaches a curated set of
 * security headers (HSTS, COOP/COEP/CORP via SecurityHeaders::tight), and optionally
 * sets Client Hints (Accept-CH) and Timing-Allow-Origin.
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\SecurityHeaders;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

/**
 * CORS and security policy middleware with route-level overrides.
 */
final readonly class CorsAndPoliciesMiddleware
{
    /**
     * @param array<int,string> $origins Allowed origins (['*'] ⇒ wildcard).
     * @param array<int,string> $acceptCh Client Hints to request via Accept-CH.
     * @param array<int,string> $timingAllowOrigins Origins for Timing-Allow-Origin (e.g., ['*'] or list).
     */
    public function __construct(
        // CORS
        private array $origins = ['*'],
        private string $methods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        private string|array $allowHeaders = ['Content-Type', 'Authorization'],
        private string|array $exposeHeaders = [
            'Content-Length',
            'Content-Type',
            'ETag',
            'Server-Timing',
            'Location',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
        ],
        private int $maxAgeSeconds = 3600,
        private bool $allowCredentials = true,
        private bool $allowPrivateNetwork = false,

        // Security / Policies
        private bool $hsts = true,
        private bool $hstsIncludeSubdomains = true,
        private ?string $csp = "default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self';",

        // Client Hints + TAO; assign blank array to disable
        private array $acceptCh = [
            'Sec-CH-UA',
            'Sec-CH-UA-Mobile',
            'Sec-CH-UA-Platform',
            'Sec-CH-UA-Arch',
            'Sec-CH-UA-Model',
            'Sec-CH-UA-Full-Version',
        ],
        private array $timingAllowOrigins = [],   // e.g. ['*'] or ['https://app.example.com']
    ) {
    }

    /**
     * Apply CORS and policies to preflight and normal requests.
     *
     * - Reads route-level #[Cors] overrides when present.
     * - Reflects Origin when allowed; otherwise uses wildcard if credentials are off.
     * - Registers Vary tokens when outcome depends on Origin or preflight headers.
     *
     * @param Request $req  Incoming request.
     * @param Closure $next Next handler.
     *
     * @return Response Response with CORS and security headers applied.
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        // Route override via #[Cors]
        $policy = [
            'origins' => $this->origins,
            'methods' => $this->methods,
            'allowHeaders' => $this->allowHeaders,
            'exposeHeaders' => $this->exposeHeaders,
            'maxAgeSeconds' => $this->maxAgeSeconds,
            'allowCredentials' => $this->allowCredentials,
            'allowPrivateNetwork' => $this->allowPrivateNetwork,
        ];

        /** @var Cors|null $route */
        $route = $req->getAttribute('cors_policy');
        if ($route instanceof Cors) {
            $policy['origins'] = $route->origins;
            $policy['methods'] = $route->methods ?? $policy['methods'];
            $policy['allowHeaders'] = $route->headers ?? $policy['allowHeaders'];
            $policy['maxAgeSeconds'] = $route->maxAgeSeconds ?? $policy['maxAgeSeconds'];
            $policy['allowCredentials'] = $route->allowCredentials ?? $policy['allowCredentials'];
        }

        $origin = $req->getHeaderLine('Origin');
        [$acao, $usedWildcard] = $this->resolveAllowedOrigin($origin, $policy['origins'], $policy['allowCredentials']);

        // Register Vary only if outcome depends on Origin
        if (!$usedWildcard && $origin !== '') {
            $req = VaryAccumulatorMiddleware::add($req, 'Origin');
        }

        /* ── Preflight ───────────────────────────────────────────── */
        if ($req->getMethod() === 'OPTIONS') {
            // preflight caches should vary on these request headers
            $req = VaryAccumulatorMiddleware::add(
                $req,
                'Access-Control-Request-Method',
                'Access-Control-Request-Headers',
            );
            // also vary when Private Network Access is in play
            $req = VaryAccumulatorMiddleware::addIf(
                $req,
                $policy['allowPrivateNetwork'],
                'Access-Control-Request-Private-Network',
            );

            $resp = new Response(204, new Stream(''));
            $resp = $this->applyCors($resp, $req, $policy, $acao, $usedWildcard, true);
            $resp = $this->applyPolicies($resp);
            return $resp;
        }

        /* ── Normal request ─────────────────────────────────────── */
        $resp = $next($req);
        $resp = $this->applyCors($resp, $req, $policy, $acao, $usedWildcard, false);
        $resp = $this->applyPolicies($resp);

        return $resp;
    }

    /* ───────────────────────── CORS ───────────────────────── */

    /**
     * Attach CORS headers to the response based on resolved policy/origin.
     *
     * @param Response $r        Response to modify.
     * @param Request  $req      Current request.
     * @param array<string,mixed> $p CORS policy.
     * @param string|null $acao  Access-Control-Allow-Origin value to reflect (or null).
     * @param bool $wildcard     Whether '*' is allowed (only when credentials=false).
     * @param bool $preflight    Whether handling a preflight request.
     *
     * @return Response Response with CORS headers.
     */
    private function applyCors(
        Response $r,
        Request $req,
        array $p,
        ?string $acao,
        bool $wildcard,
        bool $preflight,
    ): Response {
        // ACAO: reflect concrete origin when allowed; wildcard only if creds are OFF
        if ($acao !== null) {
            $r = $this->setIfAbsent($r, 'Access-Control-Allow-Origin', $acao);
        } elseif ($wildcard) {
            $r = $this->setIfAbsent($r, 'Access-Control-Allow-Origin', '*'); // only valid when creds=false
        }

        // Credentials allowed only with a specific origin (not '*')
        if ($p['allowCredentials'] && !$wildcard && $acao !== null) {
            $r = $this->setIfAbsent($r, 'Access-Control-Allow-Credentials', 'true');
        }

        // Methods
        $methods = $p['methods'];
        $reqMethod = strtoupper($req->getHeaderLine('Access-Control-Request-Method'));
        if ($preflight && $reqMethod !== '' && $this->methodAllowed($reqMethod, $methods)) {
            $methods = $reqMethod; // reflect when allowed
        }
        $r = $this->setIfAbsent($r, 'Access-Control-Allow-Methods', $methods);

        // Allow-Headers: reflect what was requested when configured as wildcard
        $allowHeaders = $this->csv($p['allowHeaders']);
        if ($preflight) {
            $requested = $req->getHeaderLine('Access-Control-Request-Headers');
            if ($requested !== '' && $this->isWildcard($p['allowHeaders'])) {
                $r = $this->setIfAbsent($r, 'Access-Control-Allow-Headers', $requested);
            } else {
                $r = $this->setIfAbsent($r, 'Access-Control-Allow-Headers', $allowHeaders);
            }
            $r = $this->setIfAbsent($r, 'Access-Control-Max-Age', (string)$p['maxAgeSeconds']);

            if ($p['allowPrivateNetwork'] &&
                strtolower($req->getHeaderLine('Access-Control-Request-Private-Network')) === 'true') {
                $r = $this->setIfAbsent($r, 'Access-Control-Allow-Private-Network', 'true');
            }
        } else {
            // harmless to send on non-preflight too (some clients look at it)
            $r = $this->setIfAbsent($r, 'Access-Control-Allow-Headers', $allowHeaders);
        }

        // Expose-Headers (client-readable response headers)
        $expose = $this->csv($p['exposeHeaders']);
        if ($expose !== '') {
            $r = $this->setIfAbsent($r, 'Access-Control-Expose-Headers', $expose);
        }

        return $r;
    }

    /* ───────────────────── Policies (no NEL here) ─────────────────── */

    /**
     * Attach security headers (HSTS, CSP, CH, TAO) when configured and absent.
     *
     * @param Response $r Response to augment.
     *
     * @return Response Response with security headers applied.
     */
    private function applyPolicies(Response $r): Response
    {
        // Security headers bundle (HSTS/COOP/COEP/CORP, etc.)
        $r = SecurityHeaders::tight(
            $r,
            hsts: $this->hsts,
            includeSubs: $this->hstsIncludeSubdomains,
        );

        // CSP (only if absent)
        if ($this->csp !== null && $this->csp !== '' && !$r->hasHeader('Content-Security-Policy')) {
            $r = $r->withSmartHeader('Content-Security-Policy', $this->csp);
        }

        // Accept-CH (only if configured & absent)
        if (!empty($this->acceptCh) && !$r->hasHeader('Accept-CH')) {
            $r = $r->withSmartHeader('Accept-CH', implode(', ', $this->acceptCh));
        }

        // Timing-Allow-Origin (optional, only if absent)
        if (!empty($this->timingAllowOrigins) && !$r->hasHeader('Timing-Allow-Origin')) {
            $tao = $this->timingAllowOrigins === ['*'] ? '*' : implode(', ', $this->timingAllowOrigins);
            $r = $r->withSmartHeader('Timing-Allow-Origin', $tao);
        }

        return $r;
    }

    /**
     * Normalize a string|array header configuration to a CSV string.
     *
     * @param string|array<int,string> $v
     *
     * @return string CSV value.
     */
    private function csv(string|array $v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        $v = array_values(array_filter(array_map(static fn ($s) => trim((string)$s), $v), fn ($s) => $s !== ''));
        return implode(', ', array_unique($v));
    }

    /**
     * Whether a header configuration represents a wildcard.
     *
     * @param string|array<int,string> $v
     *
     * @return bool True when '*' wildcard is configured.
     */
    private function isWildcard(string|array $v): bool
    {
        return is_string($v) ? trim($v) === '*' : ($v === ['*']);
    }

    /**
     * Check whether a requested method is allowed by the policy.
     *
     * @param string $method   Requested method.
     * @param string $csvList  CSV list of allowed methods.
     *
     * @return bool True if allowed.
     */
    private function methodAllowed(string $method, string $csvList): bool
    {
        $list = array_map('trim', explode(',', $csvList));
        return in_array($method, $list, true);
    }

    /**
     * Decide whether to reflect a concrete origin or use '*'.
     * Returns [acao|null, wildcardUsed].
     *
     * @param string $origin   Origin header value.
     * @param array<int,string> $allowed Allowed origins.
     * @param bool   $withCreds Whether credentials are allowed.
     *
     * @return array{0:?string,1:bool} Tuple of [ACAO value or null, wildcard used].
     */
    private function resolveAllowedOrigin(string $origin, array $allowed, bool $withCreds): array
    {
        $origin = trim($origin);
        if ($origin === '') {
            return [null, false];
        }

        $allowAny = ($allowed === ['*']);
        if ($allowAny) {
            // With credentials, '*' is illegal ⇒ reflect the Origin
            if ($withCreds) {
                return [$origin, false];
            }
            return [null, true]; // wildcard ok (no creds)
        }

        if (in_array($origin, $allowed, true)) {
            return [$origin, false];
        }

        return [null, false]; // not allowed
    }

    /**
     * Set a header only if absent.
     *
     * @param Response $r
     * @param string   $name
     * @param string   $value
     *
     * @return Response Response with header ensured.
     */
    private function setIfAbsent(Response $r, string $name, string $value): Response
    {
        return $r->hasHeader($name) ? $r : $r->withSmartHeader($name, $value);
    }
}
