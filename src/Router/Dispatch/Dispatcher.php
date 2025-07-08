<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\InterMix\DI\Invoker;
use InvalidArgumentException;

final class Dispatcher
{
    public function __construct(private readonly Invoker $invoker)
    {
    }

    /**
     * Dispatch a compiled route: apply middleware, then invoke the handler.
     *
     * @param CompiledRoute       $route   Matched route
     * @param Request             $request PSR-7–style request
     * @param array<string,mixed> $vars    Extracted URI parameters
     */
    public function __invoke(
        CompiledRoute $route,
        Request       $request,
        array         $vars
    ): Response {
        // 1) Attach route params
        $request = $request->withAttribute('route_params', $vars);

        // 2) Final handler: invoke controller, wrap non-Response as JSON
        $finalHandler = function (Request $req) use ($route): Response {
            $result = $this->invoker->invoke(
                $route->getHandler(),
                ['request' => $req]
            );
            return $result instanceof Response
                ? $result
                : Response::json($result);
        };

        // 3) Resolve and validate middleware stack
        $stack = [];
        foreach ($route->getMiddlewares() as $mw) {
            // If class-string, instantiate it
            if (is_string($mw)) {
                if (!class_exists($mw)) {
                    throw new InvalidArgumentException(
                        "Middleware class '{$mw}' not found."
                    );
                }
                $mw = new $mw();
            }

            if (!is_callable($mw)) {
                $type = is_object($mw) ? get_class($mw) : gettype($mw);
                throw new InvalidArgumentException(
                    "Middleware of type '{$type}' is not callable."
                );
            }

            $stack[] = $mw;
        }

        // 4) Build and run the pipeline
        return new MiddlewarePipeline($stack, $finalHandler)->handle($request);
    }
}
