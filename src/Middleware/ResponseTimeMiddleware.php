<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Measures end-to-end latency and surfaces it to the client.
 */
final readonly class ResponseTimeMiddleware
{
    public function __invoke(ServerRequestInterface $req, Closure $next): Response
    {
        $start = hrtime(true);   // nanoseconds
        $resp  = $next($req);
        $durMs = (hrtime(true) - $start) / 1e6;

        return $resp
            ->withHeader('X-Response-Time', sprintf('%.1fms', $durMs))
            ->withHeader('Server-Timing',    sprintf('app;dur=%.1f', $durMs));
    }
}
