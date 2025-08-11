<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

/**
 * Attaches a configurable Content-Security-Policy header.
 */
final readonly class ContentSecurityPolicyMiddleware
{
    public function __construct(
        private string $policy =
        "default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self';",
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        return $next($req)->withHeader('Content-Security-Policy', $this->policy);
    }
}
