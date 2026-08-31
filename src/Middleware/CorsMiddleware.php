<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

/** CORS-only middleware with deny/disabled defaults. */
final readonly class CorsMiddleware
{
    /** @param list<string> $origins @param string|list<string> $allowHeaders @param string|list<string> $exposeHeaders */
    public function __construct(
        private array $origins = [],
        private string $methods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        private string|array $allowHeaders = ['Content-Type', 'Authorization'],
        private string|array $exposeHeaders = [],
        private int $maxAgeSeconds = 0,
        private bool $allowCredentials = false,
        private bool $allowPrivateNetwork = false,
    ) {
        $this->validatePolicy($this->policy());
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        $policy = $this->routePolicy($req);
        $origin = trim($req->getHeaderLine('Origin'));
        if ($origin === '' || $policy['origins'] === []) {
            return $next($req);
        }

        $acao = $this->allowedOrigin($origin, $policy['origins'], $policy['allowCredentials']);
        $preflight = HttpMethodEnum::normalize($req->getMethod()) === HttpMethodEnum::OPTIONS->value
            && trim($req->getHeaderLine('Access-Control-Request-Method')) !== '';

        if ($acao === null) {
            if ($preflight) {
                throw HttpException::forbidden('CORS origin is not allowed.');
            }

            return $next($req);
        }

        if ($preflight) {
            return $this->preflightResponse($req, $policy, $acao);
        }

        return $this->applyActualHeaders($next($req), $policy, $acao);
    }

    /** @param list<string> $origins */
    private function allowedOrigin(string $origin, array $origins, bool $credentials): ?string
    {
        if ($origin === 'null') {
            return in_array('null', $origins, true) ? 'null' : null;
        }
        if (in_array('*', $origins, true)) {
            return $credentials ? null : '*';
        }

        foreach ($origins as $allowed) {
            if ($this->originMatches($origin, $allowed)) {
                return $origin;
            }
        }

        return null;
    }

    /** @param array{origins:list<string>,methods:string,allowHeaders:string|list<string>,exposeHeaders:string|list<string>,maxAgeSeconds:int,allowCredentials:bool,allowPrivateNetwork:bool} $policy */
    private function applyActualHeaders(Response $response, array $policy, string $acao): Response
    {
        $response = $response->withSmartHeader('Access-Control-Allow-Origin', $acao);
        if ($policy['allowCredentials']) {
            $response = $response->withSmartHeader('Access-Control-Allow-Credentials', 'true');
        }
        $expose = $this->csv($policy['exposeHeaders']);
        if ($expose !== '') {
            $response = $response->withSmartHeader('Access-Control-Expose-Headers', $expose);
        }

        return $acao === '*' ? $response : $response->withSmartHeader('Vary', 'Origin');
    }

    /** @param array{origins:list<string>,methods:string,allowHeaders:string|list<string>,exposeHeaders:string|list<string>,maxAgeSeconds:int,allowCredentials:bool,allowPrivateNetwork:bool} $policy */
    private function applyPreflightHeaders(Response $response, Request $req, array $policy, string $requested, string $allowed, string $acao): Response
    {
        if ($policy['allowCredentials']) {
            $response = $response->withSmartHeader('Access-Control-Allow-Credentials', 'true');
        }
        if ($requested !== '') {
            $response = $response->withSmartHeader('Access-Control-Allow-Headers', $allowed === '*' ? $requested : $allowed);
        }
        if ($policy['maxAgeSeconds'] > 0) {
            $response = $response->withSmartHeader('Access-Control-Max-Age', (string) $policy['maxAgeSeconds']);
        }

        $privateNetworkRequested = strtolower(trim($req->getHeaderLine('Access-Control-Request-Private-Network'))) === 'true';
        if ($policy['allowPrivateNetwork'] && $privateNetworkRequested) {
            $response = $response->withSmartHeader('Access-Control-Allow-Private-Network', 'true');
        }

        $response = $response->withSmartHeader('Vary', 'Access-Control-Request-Method');
        if ($requested !== '') {
            $response = $response->withSmartHeader('Vary', 'Access-Control-Request-Headers');
        }
        if ($policy['allowPrivateNetwork'] || $req->hasHeader('Access-Control-Request-Private-Network')) {
            $response = $response->withSmartHeader('Vary', 'Access-Control-Request-Private-Network');
        }

        return $acao === '*' ? $response : $response->withSmartHeader('Vary', 'Origin');
    }

    /** @param string|list<string> $value */
    private function csv(string|array $value): string
    {
        $items = is_string($value) ? explode(',', $value) : $value;
        $normalized = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '') {
                $normalized[$item] = true;
            }
        }

        return implode(', ', array_keys($normalized));
    }

    private function methodAllowed(string $method, string $methods): bool
    {
        $method = HttpMethodEnum::normalize(trim($method));

        return array_any(explode(',', $methods), fn($allowed) => HttpMethodEnum::normalize(trim($allowed)) === $method);
    }

    private function originMatches(string $origin, string $allowed): bool
    {
        $actual = parse_url($origin);
        $pattern = parse_url($allowed);
        if (!$this->validOriginParts($actual) || !$this->validOriginParts($pattern)) {
            return false;
        }
        if (strcasecmp($origin, $allowed) === 0) {
            return true;
        }

        $actualScheme = strtolower((string) ($actual['scheme'] ?? ''));
        $patternScheme = strtolower((string) ($pattern['scheme'] ?? ''));
        $actualHost = strtolower((string) ($actual['host'] ?? ''));
        $patternHost = strtolower((string) ($pattern['host'] ?? ''));
        if ($actualScheme !== $patternScheme) {
            return false;
        }

        $actualPort = $actual['port'] ?? ($actualScheme === 'https' ? 443 : 80);
        $patternPort = $pattern['port'] ?? ($patternScheme === 'https' ? 443 : 80);
        if ($actualPort !== $patternPort) {
            return false;
        }

        if (str_starts_with($patternHost, '*.')) {
            $suffix = substr($patternHost, 2);

            return $actualHost !== $suffix && str_ends_with($actualHost, '.' . $suffix);
        }

        return $actualHost === $patternHost;
    }

    /** @return array{origins:list<string>,methods:string,allowHeaders:string|list<string>,exposeHeaders:string|list<string>,maxAgeSeconds:int,allowCredentials:bool,allowPrivateNetwork:bool} */
    private function policy(): array
    {
        return [
            'origins' => array_values(array_unique(array_filter(array_map(trim(...), $this->origins)))),
            'methods' => $this->methods,
            'allowHeaders' => $this->allowHeaders,
            'exposeHeaders' => $this->exposeHeaders,
            'maxAgeSeconds' => max(0, $this->maxAgeSeconds),
            'allowCredentials' => $this->allowCredentials,
            'allowPrivateNetwork' => $this->allowPrivateNetwork,
        ];
    }

    /** @param array{origins:list<string>,methods:string,allowHeaders:string|list<string>,exposeHeaders:string|list<string>,maxAgeSeconds:int,allowCredentials:bool,allowPrivateNetwork:bool} $policy */
    private function preflightResponse(Request $req, array $policy, string $acao): Response
    {
        $requestedMethod = HttpMethodEnum::normalize(trim($req->getHeaderLine('Access-Control-Request-Method')));
        if (!$this->methodAllowed($requestedMethod, $policy['methods'])) {
            throw HttpException::forbidden('CORS method is not allowed.');
        }

        $requestedHeaders = $this->csv($req->getHeaderLine('Access-Control-Request-Headers'));
        $allowedHeaders = $this->validateRequestedHeaders($requestedHeaders, $policy['allowHeaders']);

        $response = Response::noContent([
            'Access-Control-Allow-Origin' => $acao,
            'Access-Control-Allow-Methods' => $requestedMethod,
        ]);

        return $this->applyPreflightHeaders($response, $req, $policy, $requestedHeaders, $allowedHeaders, $acao);
    }

    /** @return array{origins:list<string>,methods:string,allowHeaders:string|list<string>,exposeHeaders:string|list<string>,maxAgeSeconds:int,allowCredentials:bool,allowPrivateNetwork:bool} */
    private function routePolicy(Request $req): array
    {
        $policy = $this->policy();
        $route = $req->getAttribute('cors_policy');
        if (!$route instanceof Cors) {
            return $policy;
        }

        $policy['origins'] = array_values(array_unique(array_filter(array_map(trim(...), $route->origins))));
        $policy['methods'] = $route->methods ?? $policy['methods'];
        $policy['allowHeaders'] = $route->headers ?? $policy['allowHeaders'];
        $policy['exposeHeaders'] = $route->exposeHeaders ?? $policy['exposeHeaders'];
        $policy['maxAgeSeconds'] = max(0, $route->maxAgeSeconds ?? $policy['maxAgeSeconds']);
        $policy['allowCredentials'] = $route->allowCredentials ?? $policy['allowCredentials'];
        $policy['allowPrivateNetwork'] = $route->allowPrivateNetwork ?? $policy['allowPrivateNetwork'];
        $this->validatePolicy($policy);

        return $policy;
    }

    /** @param array{origins:list<string>,methods:string,allowHeaders:string|list<string>,exposeHeaders:string|list<string>,maxAgeSeconds:int,allowCredentials:bool,allowPrivateNetwork:bool} $policy */
    private function validatePolicy(array $policy): void
    {
        if ($policy['allowCredentials'] && in_array('*', $policy['origins'], true)) {
            throw new \InvalidArgumentException('CORS credentials require explicit origins; wildcard origin is not allowed.');
        }
    }

    /** @param string|list<string> $configured */
    private function validateRequestedHeaders(string $requested, string|array $configured): string
    {
        $allowed = $this->csv($configured);
        if ($requested === '' || $allowed === '*') {
            return $allowed;
        }

        $allowedSet = array_fill_keys(array_map(strtolower(...), array_map(trim(...), explode(',', $allowed))), true);
        foreach (array_map(trim(...), explode(',', $requested)) as $header) {
            if (!isset($allowedSet[strtolower($header)])) {
                throw HttpException::forbidden('CORS request header is not allowed.');
            }
        }

        return $allowed;
    }

    /** @param array<string,mixed>|false $parts */
    private function validOriginParts(array|false $parts): bool
    {
        if (!is_array($parts)) {
            return false;
        }
        if (!is_string($parts['scheme'] ?? null) || $parts['scheme'] === '' || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return false;
        }

        return array_all(['user', 'pass', 'path', 'query', 'fragment'], fn($part) => !(isset($parts[$part]) && $parts[$part] !== ''));
    }
}
