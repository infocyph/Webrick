<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Short-circuits all requests with 503 when a "down" file exists.
 * Kept standalone in the new layout.
 */
final readonly class MaintenanceModeMiddleware
{
    public function __construct(
        private string $file = __DIR__ . '/../../storage/framework/down',
        private int $retryAfter = 3600,
        private string $contentType = 'text/plain; charset=utf-8',
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        if (!\is_file($this->file)) {
            return $next($req);
        }

        $payload = \file_get_contents($this->file) ?: 'Service is down for maintenance.';

        return Response::plaintext($payload, 503)
            ->withHeader('Retry-After', (string)$this->retryAfter)
            ->withHeader('Content-Type', $this->contentType);
    }
}
