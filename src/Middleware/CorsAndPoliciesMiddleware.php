<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\SecurityHeaders;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

final readonly class CorsAndPoliciesMiddleware
{
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

        // Client Hints + TAO
        private array $acceptCh = [
            'Sec-CH-UA',
            'Sec-CH-UA-Mobile',
            'Sec-CH-UA-Platform',
            'Sec-CH-UA-Arch',
            'Sec-CH-UA-Model',
            'Sec-CH-UA-Full-Version',
        ],
        private array $timingAllowOrigins = [],
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
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
            $policy['exposeHeaders'] = $route->exposeHeaders ?? $policy['exposeHeaders'];
            $policy['maxAgeSeconds'] = $route->maxAgeSeconds ?? $policy['maxAgeSeconds'];
            $policy['allowCredentials'] = $route->allowCredentials ?? $policy['allowCredentials'];
            $policy['allowPrivateNetwork'] = $route->allowPrivateNetwork ?? $policy['allowPrivateNetwork'];
        }

        $origin = $req->getHeaderLine('Origin');
        [$acao, $usedWildcard] = $this->resolveAllowedOrigin($origin, $policy['origins'], $policy['allowCredentials']);

        if (!$usedWildcard && $origin !== '') {
            $req = VaryAccumulatorMiddleware::add($req, 'Origin');
        }

        if ($req->getMethod() === 'OPTIONS') {
            $req = VaryAccumulatorMiddleware::add(
                $req,
                'Access-Control-Request-Method',
                'Access-Control-Request-Headers',
            );
            $req = VaryAccumulatorMiddleware::addIf(
                $req,
                $policy['allowPrivateNetwork'],
                'Access-Control-Request-Private-Network',
            );

            $resp = new Response(204, new Stream(''));
            $resp = $this->applyCors($resp, $req, $policy, $acao, $usedWildcard, true);
            return $this->applyPolicies($resp);
        }

        $resp = $next($req);
        $resp = $this->applyCors($resp, $req, $policy, $acao, $usedWildcard, false);
        return $this->applyPolicies($resp);
    }

    /* ───────────────────────── CORS ───────────────────────── */

    /**
     * Orchestrates CORS header application by delegating to smaller helpers.
     *
     * @param array<string,mixed> $p
     */
    private function applyCors(
        Response $r,
        Request $req,
        array $p,
        ?string $acao,
        bool $wildcard,
        bool $preflight,
    ): Response {
        $r = $this->setAcaoAndCredentials($r, $p, $acao, $wildcard);
        $r = $this->setAllowMethods($r, $req, $p, $preflight);
        $r = $this->setAllowHeaders($r, $req, $p, $preflight);
        $r = $this->setExposeHeaders($r, $p);
        return $r;
    }

    /* ───────────────────── Policies (no NEL here) ─────────────────── */

    private function applyPolicies(Response $r): Response
    {
        $r = SecurityHeaders::tight($r, hsts: $this->hsts, includeSubs: $this->hstsIncludeSubdomains);

        if ($this->csp !== null && $this->csp !== '' && !$r->hasHeader('Content-Security-Policy')) {
            $r = $r->withSmartHeader('Content-Security-Policy', $this->csp);
        }
        if (!empty($this->acceptCh) && !$r->hasHeader('Accept-CH')) {
            $r = $r->withSmartHeader('Accept-CH', implode(', ', $this->acceptCh));
        }
        if (!empty($this->timingAllowOrigins) && !$r->hasHeader('Timing-Allow-Origin')) {
            $tao = $this->timingAllowOrigins === ['*'] ? '*' : implode(', ', $this->timingAllowOrigins);
            $r = $r->withSmartHeader('Timing-Allow-Origin', $tao);
        }
        return $r;
    }

    /**
     * @return array{scheme:string,host:string,port:int,origin:string}
     */
    private function canonicalOrigin(string $origin): array
    {
        $p = parse_url($origin);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = strtolower($p['host'] ?? '');
        $port = $p['port'] ?? (($scheme === 'https') ? 443 : (($scheme === 'http') ? 80 : null));

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port ?? -1,
            'origin' => $origin,
        ];
    }

    /* ───────────────────── Helpers & matchers ─────────────────── */

    private function csv(string|array $v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        $v = array_values(array_filter(array_map(static fn ($s) => trim((string)$s), $v), fn ($s) => $s !== ''));
        return implode(', ', array_unique($v));
    }

    private function isWildcard(string|array $v): bool
    {
        return is_string($v) ? trim($v) === '*' : ($v === ['*']);
    }

    private function matchOriginPattern(array $o, string $pattern): bool
    {
        if (strcasecmp($o['origin'], $pattern) === 0) {
            return true;
        }

        $pp = parse_url($pattern);
        $ps = strtolower($pp['scheme'] ?? '');
        $ph = strtolower($pp['host'] ?? '');
        $pt = $pp['port'] ?? null;

        if ($ps === '' || $ph === '' || $ps !== $o['scheme']) {
            return false;
        }
        if ($pt !== null && (int)$pt !== (int)$o['port']) {
            return false;
        }

        if (str_starts_with($ph, '*.')) {
            $suffix = substr($ph, 2);
            return $o['host'] === $suffix || str_ends_with($o['host'], '.' . $suffix);
        }

        return $o['host'] === $ph;
    }

    private function methodAllowed(string $method, string $csvList): bool
    {
        $list = array_map('trim', explode(',', $csvList));
        return in_array($method, $list, true);
    }

    /**
     * @return array{0:?string,1:bool}
     */
    private function resolveAllowedOrigin(string $origin, array $allowed, bool $withCreds): array
    {
        $origin = trim($origin);
        if ($origin === '') {
            return [null, false];
        }

        if ($origin === 'null') {
            $allowsNull = array_reduce(
                $allowed,
                static fn (bool $c, string $p) => $c || strtolower(trim($p)) === 'null',
                false,
            );
            return $allowsNull ? ['null', false] : [null, false];
        }

        if ($allowed === ['*']) {
            return $withCreds ? [$origin, false] : [null, true];
        }

        $o = $this->canonicalOrigin($origin);

        foreach ($allowed as $pat) {
            $pat = trim($pat);
            if ($pat === '') {
                continue;
            }
            if (strcasecmp($pat, $origin) === 0 || $this->matchOriginPattern($o, $pat)) {
                return [$origin, false];
            }
        }

        return [null, false];
    }

    /**
     * ACAO + ACAC
     *
     * @param array<string,mixed> $p
     */
    private function setAcaoAndCredentials(Response $r, array $p, ?string $acao, bool $wildcard): Response
    {
        if ($acao !== null) {
            $r = $this->setIfAbsent($r, 'Access-Control-Allow-Origin', $acao);
        } elseif ($wildcard) {
            $r = $this->setIfAbsent($r, 'Access-Control-Allow-Origin', '*');
        }

        if ($p['allowCredentials'] && !$wildcard && $acao !== null) {
            $r = $this->setIfAbsent($r, 'Access-Control-Allow-Credentials', 'true');
        }
        return $r;
    }

    /**
     * ACAH + ACMA (+ ACAPN)
     *
     * @param array<string,mixed> $p
     */
    private function setAllowHeaders(Response $r, Request $req, array $p, bool $preflight): Response
    {
        $allowHeadersCsv = $this->csv($p['allowHeaders']);
        if ($preflight) {
            $requested = $req->getHeaderLine('Access-Control-Request-Headers');
            if ($requested !== '' && $this->isWildcard($p['allowHeaders'])) {
                $reqList = array_filter(array_map(static fn ($h) => strtolower(trim($h)), explode(',', $requested)));
                $requestedNormalized = implode(', ', array_unique($reqList));
                $r = $this->setIfAbsent($r, 'Access-Control-Allow-Headers', $requestedNormalized);
            } else {
                $r = $this->setIfAbsent($r, 'Access-Control-Allow-Headers', strtolower($allowHeadersCsv));
            }
            $r = $this->setIfAbsent($r, 'Access-Control-Max-Age', (string)$p['maxAgeSeconds']);

            if ($p['allowPrivateNetwork'] &&
                strtolower($req->getHeaderLine('Access-Control-Request-Private-Network')) === 'true') {
                $r = $this->setIfAbsent($r, 'Access-Control-Allow-Private-Network', 'true');
            }
        } else {
            $r = $this->setIfAbsent($r, 'Access-Control-Allow-Headers', strtolower($allowHeadersCsv));
        }

        return $r;
    }

    /**
     * ACAM (reflect requested method on preflight when allowed).
     *
     * @param array<string,mixed> $p
     */
    private function setAllowMethods(Response $r, Request $req, array $p, bool $preflight): Response
    {
        $methods = $p['methods'];
        $reqMethod = strtoupper($req->getHeaderLine('Access-Control-Request-Method'));
        if ($preflight && $reqMethod !== '' && $this->methodAllowed($reqMethod, $methods)) {
            $methods = $reqMethod;
        }
        return $this->setIfAbsent($r, 'Access-Control-Allow-Methods', $methods);
    }

    /**
     * ACEH (supports '*' when credentials are off).
     *
     * @param array<string,mixed> $p
     */
    private function setExposeHeaders(Response $r, array $p): Response
    {
        $expose = $this->csv($p['exposeHeaders']);
        if ($expose === '') {
            return $r;
        }
        if (!$p['allowCredentials'] && $this->isWildcard($p['exposeHeaders'])) {
            return $this->setIfAbsent($r, 'Access-Control-Expose-Headers', '*');
        }
        return $this->setIfAbsent($r, 'Access-Control-Expose-Headers', $expose);
    }

    private function setIfAbsent(Response $r, string $name, string $value): Response
    {
        return $r->hasHeader($name) ? $r : $r->withSmartHeader($name, $value);
    }
}
