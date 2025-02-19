<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteCollection
{
    /**
     * Static routes, indexed by [METHOD][PATH].
     *
     * @var array<string, array<string, RouteInterface>>
     */
    private array $staticRoutes = [];

    /**
     * Dynamic routes containing placeholders.
     *
     * Each entry looks like:
     * [
     *   'method'     => 'GET',
     *   'pattern'    => '/^\/user\/(?P<id>[^/]+)$/',
     *   'paramNames' => ['id'],
     *   'route'      => RouteInterface
     * ]
     *
     * @var array<int, array<string, mixed>>
     */
    private array $dynamicRoutes = [];

    public function __construct(private readonly RouteParser $parser)
    {
    }

    public function addRoute(RouteInterface $route): void
    {
        $method = strtoupper($route->getMethod());
        $path = $route->getPath();

        // If the path has placeholders, parse it
        if (str_contains($path, '{')) {
            $parsed = $this->parser->parse($path);
            $this->dynamicRoutes[] = [
                'method' => $method,
                'pattern' => $parsed['pattern'],
                'paramNames' => $parsed['paramNames'],
                'route' => $route,
            ];
        } else {
            // Static (exact) path
            $this->staticRoutes[$method][$path] = $route;
        }
    }

    /**
     * Attempt to find a matching route.
     *
     * @return array{0: RouteInterface, 1: array<string, mixed>} [matchedRoute, matchedParams]
     *
     * @throws MethodNotAllowedException
     * @throws RouteNotFoundException
     */
    public function match(ServerRequestInterface $request): array
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // 1) Check static routes first
        if (isset($this->staticRoutes[$method][$path])) {
            return [$this->staticRoutes[$method][$path], []];
        }

        // 2) Check dynamic routes
        $foundAnyMatchingPath = false;
        foreach ($this->dynamicRoutes as $data) {
            if (preg_match($data['pattern'], $path, $matches)) {
                $foundAnyMatchingPath = true;
                if ($data['method'] === $method) {
                    // We have a full match
                    $params = [];
                    foreach ($data['paramNames'] as $pName) {
                        $params[$pName] = $matches[$pName] ?? null;
                    }

                    return [$data['route'], $params];
                }
            }
        }

        // If path matched but not the method => 405
        if ($foundAnyMatchingPath) {
            throw new MethodNotAllowedException("Method '{$method}' not allowed for path '{$path}'.");
        }

        // Otherwise => 404
        throw new RouteNotFoundException("No route found for {$method} {$path}");
    }
}
