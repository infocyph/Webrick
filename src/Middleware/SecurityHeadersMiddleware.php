<?php

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\SecurityHeaders;
use Infocyph\Webrick\Response\Response;

final readonly class SecurityHeadersMiddleware
{
    public function __construct(
        private bool $hsts = true,
        private bool $includeSubs = true,
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);
        return SecurityHeaders::tight(
            $resp,
            hsts: $this->hsts,
            includeSubs: $this->includeSubs,
        );
    }
}
