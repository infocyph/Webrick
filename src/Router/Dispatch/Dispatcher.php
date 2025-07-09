<?php

// src/Router/Dispatch/Dispatcher.php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\InterMix\DI\Invoker;
use InvalidArgumentException;

final readonly class Dispatcher
{
    public function __construct(private Invoker $invoker)
    {
    }

    public function dispatch(
        CompiledRoute $route,
        Request       $request,
        array         $vars
    ): Response {
        $request = $request->withAttribute('route_params', $vars);

        /* ---------------------------------------------------------
         * 1. Final handler (unchanged)
         * --------------------------------------------------------*/
        $final = function (Request $req) use ($route): Response {
            $result = $this->invoker->invoke($route->getHandler(), ['request' => $req]);

            return $result instanceof Response
                ? $result
                : Response::json($result);
        };

        /* ---------------------------------------------------------
         * 2. Build *lazy* middleware chain
         * --------------------------------------------------------*/
        $stack = [];

        foreach ($route->getMiddlewares() as $mw) {
            // ── Case A: already a callable object/closure ──────────────────────
            if (is_object($mw)) {
                if (!is_callable($mw)) {
                    throw new InvalidArgumentException(
                        sprintf("Middleware object of class %s is not callable", $mw::class)
                    );
                }
                $stack[] = $mw;
                continue;
            }

            if (is_string($mw)) {
                // ─ class-string: instantiate on first use, then cache ─
                if (!class_exists($mw)) {
                    throw new InvalidArgumentException("Middleware class '{$mw}' not found.");
                }

                /** @var class-string $mw */
                $stack[] = function (Request $req, callable $next) use ($mw): Response {
                    static $instance = null;                 // ← cache between requests
                    $instance ??= $this->invoker->make($mw); // constructor-DI via Invoker
                    return $instance($req, $next);
                };
                continue;
            }

            throw new InvalidArgumentException(
                sprintf("Middleware of type %s is not supported", gettype($mw))
            );
        }

        /* ---------------------------------------------------------
         * 3. Fire the pipeline
         * --------------------------------------------------------*/
        return new MiddlewarePipeline($stack, $final)->handle($request);
    }
}
