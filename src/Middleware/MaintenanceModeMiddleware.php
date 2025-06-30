<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Returns **503 Service Unavailable** when the "down file" exists.
 *
 * Usage:
 *     $router->getContainer()->definitions()
 *            ->bind(MaintenanceModeMiddleware::class,
 *                   new MaintenanceModeMiddleware(__DIR__.'/../../storage/down'));
 *
 * …and add it to the global stack.
 */
final readonly class MaintenanceModeMiddleware
{
    public function __construct(
        private string $file = __DIR__ . '/../../storage/framework/down'
    ) {}

    public function __invoke(ServerRequestInterface $req, Closure $next): Response
    {
        if (! \file_exists($this->file)) {
            return $next($req);
        }

        $payload = \file_get_contents($this->file) ?: 'Service is down for maintenance.';
        return new Response(
            status  : 503,
            headers : ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '3600'],
            body    : new Stream($payload)
        );
    }
}
