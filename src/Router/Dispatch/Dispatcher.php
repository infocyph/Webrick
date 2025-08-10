<?php

// src/Router/Dispatch/Dispatcher.php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Psr7\ServerRequest;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use InvalidArgumentException;

/**
 * Dispatches a CompiledRoute:
 *   • builds the MiddlewarePipeline the first time a route is hit
 *   • caches it in $this->pipelines for all subsequent requests
 */
final class Dispatcher
{
    /** @var array<int,MiddlewarePipeline> route-id ⇒ compiled pipeline */
    private array $pipelines = [];

    public function __construct(private readonly Invoker $invoker) {}

    public function dispatch(
        CompiledRoute $route,
        Request $request,
        array $vars,
    ): Response {
        // expose path params to downstream code
        $request = $request->withAttribute('route_params', $vars);

        if (method_exists($route, 'getCorsPolicy') && $corsPolicy = $route->getCorsPolicy()) {
            $request = $request->withAttribute('cors_policy', $corsPolicy);
        }

        $container = $this->invoker->getContainer();
        $defs = $container->definitions();
        $defs->bind(Request::class, $request);
        $defs->bind(ServerRequest::class, $request);

        // ① Index is perfect (numeric, monotonic) – use it when present
        $routeId = $route->getIndex();

        // build + memoise the pipeline once
        $this->pipelines[$routeId] ??= $this->compilePipeline($route);

        return $this->pipelines[$routeId]->handle($request);
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    private function compilePipeline(CompiledRoute $route): MiddlewarePipeline
    {
        /* -- terminal handler ------------------------------------------- */
        $invoker = $this->invoker;
        $final = static function () use ($route, $invoker): Response {
            $result = $invoker->invoke($route->getHandler());
            return $result instanceof Response ? $result : Response::json($result);
        };

        /* -- middleware stack ------------------------------------------- */
        $stack = [];

        foreach ($route->getMiddlewares() as $mw) {
            // callable object / closure
            if (\is_object($mw)) {
                if (!\is_callable($mw)) {
                    throw new InvalidArgumentException(
                        sprintf('Middleware object %s is not callable', $mw::class),
                    );
                }
                $stack[] = $mw;
                continue;
            }

            // class-string
            if (\is_string($mw)) {
                if (!\class_exists($mw)) {
                    throw new InvalidArgumentException("Middleware class '{$mw}' not found.");
                }

                $stack[] = static function (Request $req, callable $next) use ($mw, $invoker): Response {
                    static $instance = null;              // one per worker process
                    $instance ??= $invoker->make($mw);    // constructor DI once
                    return $instance($req, $next);
                };
                continue;
            }

            throw new InvalidArgumentException(
                sprintf('Unsupported middleware of type %s', \gettype($mw)),
            );
        }

        return new MiddlewarePipeline($stack, $final);
    }
}
