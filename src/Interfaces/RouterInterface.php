<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

interface RouterInterface
{
    /**
     * Add a route to the router.
     *
     * The route is identified by the given HTTP method and path.
     * The handler is the callable that will be executed when the route is matched.
     *
     * @param string $method The HTTP method of the route.
     * @param string $path The path of the route.
     * @param callable $handler The handler of the route.
     * @return RouteInterface The added route.
     */
    public function addRoute(
        string $method,
        string $path,
        callable $handler,
    ): RouteInterface;

    /**
     * Add a GET route to the router.
     *
     * The route is identified by the given path.
     * The handler is the callable that will be executed when the route is matched.
     *
     * @param string $path The path of the route.
     * @param callable $handler The handler of the route.
     * @return RouteInterface The added route.
     */
    public function get(string $path, callable $handler): RouteInterface;

    /**
     * Add a POST route to the router.
     *
     * The route is identified by the given path.
     * The handler is the callable that will be executed when the route is matched.
     *
     * @param string $path The path of the route.
     * @param callable $handler The handler of the route.
     * @return RouteInterface The added route.
     */
    public function post(string $path, callable $handler): RouteInterface;

    /**
     * Add a PUT route to the router.
     *
     * The route is identified by the given path.
     * The handler is the callable that will be executed when the route is matched.
     *
     * @param string $path The path of the route.
     * @param callable $handler The handler of the route.
     * @return RouteInterface The added route.
     */
    public function put(string $path, callable $handler): RouteInterface;

    /**
     * Add a PATCH route to the router.
     *
     * The route is identified by the given path.
     * The handler is the callable that will be executed when the route is matched.
     *
     * @param string $path The path of the route.
     * @param callable $handler The handler of the route.
     * @return RouteInterface The added route.
     */
    public function patch(string $path, callable $handler): RouteInterface;

    /**
     * Add a DELETE route to the router.
     *
     * The route is identified by the given path.
     * The handler is the callable that will be executed when the route is matched.
     *
     * @param string $path The path of the route.
     * @param callable $handler The handler of the route.
     * @return RouteInterface The added route.
     */
    public function delete(string $path, callable $handler): RouteInterface;

    /**
     * Add a HEAD route to the router.
     *
     * The route is identified by the given path.
     * The handler is the callable that will be executed when the route is matched.
     *
     * @param string $path The path of the route.
     * @param callable $handler The handler of the route.
     * @return RouteInterface The added route.
     */
    public function head(string $path, callable $handler): RouteInterface;

    /**
     * Add an OPTIONS route to the router.
     *
     * The route is identified by the given path.
     * The handler is the callable that will be executed when the route is matched.
     *
     * @param string $path The path of the route.
     * @param callable $handler The handler of the route.
     * @return RouteInterface The added route.
     */
    public function options(string $path, callable $handler): RouteInterface;

    /**
     * Handle a request and return a response.
     *
     * @param Request $request The request to be handled.
     * @return Response The response to the request.
     */
    public function handle(Request $request): Response;

    /**
     * Generate a URL for the given route name.
     *
     * @param string $name The name of the route.
     * @param array $params The parameters for the route.
     * @param bool $absolute Whether to generate an absolute URL.
     * @return string The generated URL.
     */
    public function urlFor(
        string $name,
        array $params = [],
        bool $absolute = false,
    ): string;
}
