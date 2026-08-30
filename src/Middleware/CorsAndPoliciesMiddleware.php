<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Compatibility composition of the now-independent CORS and security policy
 * middleware. New Webrick 5 applications should register them separately.
 */
final readonly class CorsAndPoliciesMiddleware
{
    private CorsMiddleware $cors;

    private SecurityPolicyMiddleware $security;

    /**
     * @param list<string> $origins
     * @param string|list<string> $allowHeaders
     * @param string|list<string> $exposeHeaders
     * @param list<string> $acceptCh
     * @param list<string> $timingAllowOrigins
     */
    public function __construct(
        array $origins = [],
        string $methods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        string|array $allowHeaders = ['Content-Type', 'Authorization'],
        string|array $exposeHeaders = [],
        int $maxAgeSeconds = 0,
        bool $allowCredentials = false,
        bool $allowPrivateNetwork = false,
        bool $hsts = true,
        bool $hstsIncludeSubdomains = true,
        ?string $csp = "default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self';",
        array $acceptCh = [],
        array $timingAllowOrigins = [],
    ) {
        $this->cors = new CorsMiddleware(
            $origins,
            $methods,
            $allowHeaders,
            $exposeHeaders,
            $maxAgeSeconds,
            $allowCredentials,
            $allowPrivateNetwork,
        );
        $this->security = new SecurityPolicyMiddleware(
            $hsts,
            $hstsIncludeSubdomains,
            $csp,
            $acceptCh,
            $timingAllowOrigins,
        );
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        return ($this->security)(
            $req,
            fn(Request $request): Response => ($this->cors)($request, $next),
        );
    }
}
