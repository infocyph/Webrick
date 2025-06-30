<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Converts uncaught exceptions into HTTP responses.
 *
 * Works with Webrick’s routing pipeline signature:
 *   (ServerRequestInterface $request, Closure $next): Response
 */
final readonly class ErrorHandlerMiddleware
{
    public function __construct(private bool $devMode = false)
    {
    }

    public function __invoke(ServerRequestInterface $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (RouteNotFoundException $e) {
            return $this->build(404, $e);
        } catch (MethodNotAllowedException $e) {
            return $this->build(405, $e, ['Allow' => implode(', ', $e->allowed)]);
        } catch (Throwable $e) {
            return $this->build(500, $e);
        }
    }

    /* ------------------------------------------------------------------ */

    private function build(int $status, Throwable $e, array $headers = []): Response
    {
        $payload = $this->devMode
            ? "[DEBUG] $e: {$e->getMessage()}\n\n{$e->getTraceAsString()}"
            : ($e->getMessage() ?: 'Unexpected error.');

        $headers += ['Content-Type' => 'text/plain; charset=utf-8'];

        return new Response(
            status  : $status,
            body    : new Stream($payload),
            headers : $headers
        );
    }
}
