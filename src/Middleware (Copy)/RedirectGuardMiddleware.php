<?php

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class RedirectGuardMiddleware
{
    public function __construct(private array $allowedHosts) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);

        if ($resp->hasHeader('Location')) {
            $loc = $resp->getHeaderLine('Location');
            $host = parse_url($loc, PHP_URL_HOST);
            if ($host && !in_array($host, $this->allowedHosts, true)) {
                return Response::json(['error' => 'Open redirect blocked'], 400);
            }
        }
        return $resp;
    }
}
