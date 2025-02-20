<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Psr\Container\ContainerInterface;

/**
 * The "final" step that calls the matched route's handler.
 * Optionally uses a PSR-11 container to resolve class-based handlers.
 */
class RouteDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private ?RouteInterface $route = null,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public function setRoute(RouteInterface $route): void
    {
        $this->route = $route;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->route) {
            throw new RuntimeException('No route set in RouteDispatcher.');
        }

        $handler = $this->route->getHandler();

        // If user used "Controller@method"
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler);
            $instance = $this->resolveClass($class);
            $handler = [$instance, $method];
        } // If array-based
        elseif (is_array($handler) && isset($handler[0], $handler[1])) {
            [$class, $method] = $handler;
            if (is_string($class)) {
                $classInstance = $this->resolveClass($class);
                $handler = [$classInstance, $method];
            }
        }

        $response = \call_user_func($handler, $request);
        if (!$response instanceof ResponseInterface) {
            throw new RuntimeException('Route handler did not return a valid ResponseInterface.');
        }
        return $response;
    }

    private function resolveClass(string $class)
    {
        if ($this->container && $this->container->has($class)) {
            return $this->container->get($class);
        }
        return new $class();
    }
}
