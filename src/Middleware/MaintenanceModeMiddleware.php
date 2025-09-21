<?php

/**
 * Webrick - Maintenance mode middleware.
 *
 * Short-circuits all requests with HTTP 503 when a sentinel "down" file exists.
 * Useful during deployments or maintenance windows to return a consistent
 * Retry-After and Content-Type. Kept standalone in the new layout.
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Responds with 503 Service Unavailable when a maintenance file is present.
 */
final readonly class MaintenanceModeMiddleware
{
    /**
     * Configure maintenance toggle and response details.
     *
     * @param string $file        Absolute path to the "down" file that enables maintenance mode.
     * @param int    $retryAfter  Seconds clients should wait before retrying.
     * @param string $contentType Content-Type for the maintenance response.
     */
    public function __construct(
        private string $file = __DIR__ . '/../../storage/framework/down',
        private int $retryAfter = 3600,
        private string $contentType = 'text/plain; charset=utf-8',
    ) {
    }

    /**
     * If the maintenance file exists, return a 503 response; otherwise continue.
     *
     * The response body contains the contents of the "down" file when non-empty,
     * or a default message. Retry-After and Content-Type headers are set accordingly.
     *
     * @param Request $req  Incoming request.
     * @param Closure $next Next handler in the pipeline.
     *
     * @return Response 503 maintenance response or the downstream response.
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        if (!\is_file($this->file)) {
            return $next($req);
        }

        $payload = \file_get_contents($this->file) ?: 'Service is down for maintenance.';

        return Response::plaintext($payload, 503)
            ->withSmartHeader('Retry-After', (string)$this->retryAfter)
            ->withSmartHeader('Content-Type', $this->contentType);
    }
}