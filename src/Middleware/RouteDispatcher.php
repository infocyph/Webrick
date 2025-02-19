<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * A minimal "final handler" that calls the matched route's callable.
 * This can be used directly, or replaced with a more complex implementation
 * that uses DI containers, logging, etc.
 */
class RouteDispatcher implements RequestHandlerInterface
{
    public function __construct(private ?RouteInterface $route = null)
    {
    }

    /**
     * Optionally update the route before handling.
     */
    public function setRoute(RouteInterface $route): void
    {
        $this->route = $route;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->route) {
            throw new RuntimeException('No RouteInterface set in RouteDispatcher.');
        }

        $handler = $this->route->getHandler();
        $response = \call_user_func($handler, $request);

        if (! $response instanceof ResponseInterface) {
            throw new RuntimeException('Route handler did not return a valid ResponseInterface.');
        }

        return $response;
    }
}
