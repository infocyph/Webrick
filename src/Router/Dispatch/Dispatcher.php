<?php

// src/Router/Dispatch/Dispatcher.php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\InterMix\DI\Invoker;
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

    public function __construct(private readonly Invoker $invoker)
    {
    }

    public function dispatch(
        CompiledRoute $route,
        Request $request,
        array $vars,
    ): Response {
        if (method_exists($route, 'getCorsPolicy') && $corsPolicy = $route->getCorsPolicy()) {
            $request = $request->withAttribute('cors_policy', $corsPolicy);
        }

        $invoker = $this->invoker;

        $final = static function (Request $req) use ($route, $vars, $invoker): Response {
            $result = $invoker->invoke($route->getHandler(), $vars);
            return $result instanceof Response ? $result : Response::json($result);
        };

        // Index is perfect (numeric, monotonic) – use it when present
        $routeId = $route->getIndex();

        // build + memoise the pipeline once
        $this->pipelines[$routeId] ??= $this->compilePipeline($route, $final, $invoker);

        return $this->pipelines[$routeId]->handle($request);
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    private function compilePipeline(CompiledRoute $route, Closure $final, Invoker $invoker): MiddlewarePipeline
    {
        $stack = [];
        foreach ($route->getMiddlewares() as $mw) {
            $stack[] = match (true) {
                \is_object($mw) => match (true) {
                    \is_callable($mw) => $mw,
                    default => throw new InvalidArgumentException(
                        sprintf('Middleware object %s is not callable', $mw::class),
                    ),
                },

                \is_string($mw) => match (true) {
                    !\class_exists($mw) => throw new InvalidArgumentException("Middleware class '{$mw}' not found."),
                    default => static function (Request $req, callable $next) use ($mw, $invoker): Response {
                        static $instance = null;
                        $instance ??= $invoker->make($mw);
                        if (!\is_callable($instance)) {
                            throw new InvalidArgumentException("Middleware {$mw} must be invokable (__invoke).");
                        }
                        return $instance($req, $next);
                    },
                },

                default => throw new InvalidArgumentException(
                    sprintf('Unsupported middleware of type %s', \gettype($mw)),
                ),
            };
        }
        return new MiddlewarePipeline($stack, $final);
    }
}
