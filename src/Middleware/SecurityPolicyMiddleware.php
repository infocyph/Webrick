<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\SecurityHeaders;
use Infocyph\Webrick\Response\Response;

/** HTTP response policies separated from CORS semantics. */
final readonly class SecurityPolicyMiddleware
{
    private ?string $acceptChHeader;

    private ?string $timingAllowOriginHeader;

    /**
     * @param list<string> $acceptCh
     * @param list<string> $timingAllowOrigins
     */
    public function __construct(
        private bool $hsts = true,
        private bool $hstsIncludeSubdomains = true,
        private ?string $csp = "default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self';",
        array $acceptCh = [],
        array $timingAllowOrigins = [],
    ) {
        $this->acceptChHeader = $acceptCh === [] ? null : implode(', ', $acceptCh);
        $this->timingAllowOriginHeader = match ($timingAllowOrigins) {
            [] => null,
            ['*'] => '*',
            default => implode(', ', $timingAllowOrigins),
        };
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        $response = SecurityHeaders::tight(
            $next($req),
            hsts: $this->hsts,
            includeSubs: $this->hstsIncludeSubdomains,
            secureRequest: $req->isSecure(),
        );

        if ($this->csp !== null && $this->csp !== '' && !$response->hasHeader('Content-Security-Policy')) {
            $response = $response->withSmartHeader('Content-Security-Policy', $this->csp);
        }
        if ($this->acceptChHeader !== null && !$response->hasHeader('Accept-CH')) {
            $response = $response->withSmartHeader('Accept-CH', $this->acceptChHeader);
        }
        if ($this->timingAllowOriginHeader !== null && !$response->hasHeader('Timing-Allow-Origin')) {
            $response = $response->withSmartHeader('Timing-Allow-Origin', $this->timingAllowOriginHeader);
        }

        return $response;
    }
}
