<?php

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

    /**
     * @param array<string,string> $vars URI parameters from the matcher
     */
    public function dispatch(
        CompiledRoute $route,
        Request       $request,
        array         $vars = []
    ): Response {
        /* 1️⃣  stash params on the request (cheap clone) */
        $request = $request->withAttribute('route_params', $vars);

        /* 2️⃣  final endpoint – delegate to Invoker */
        $final = function (Request $req) use ($route, $vars) {
            return $this->invoker->invoke(
                $route->getHandler(),
                ['request' => $req, ...$vars]        // <- makes vars injectable
            );
        };

        /* 3️⃣  hydrate middleware stack (DI-friendly) */
        $stack = \array_map(function ($mw) {
            if (\is_string($mw)) {           // class-string → make()
                return $this->invoker->make($mw);
            }
            if (!\is_callable($mw)) {
                $type = \is_object($mw) ? $mw::class : \gettype($mw);
                throw new InvalidArgumentException("Middleware '{$type}' is not callable.");
            }
            return $mw;
        }, $route->getMiddlewares());

        /* 4️⃣  run pipeline */
        $response = new MiddlewarePipeline($stack, $final)->handle($request);

        /* 5️⃣  JSON-encode scalars/arrays for convenience */
        return $response instanceof Response
            ? $response
            : Response::json($response);
    }
}
