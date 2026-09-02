<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Dispatches a CompiledRoute and composes the middleware pipeline.
 */
final class Dispatcher
{
    private readonly RuntimeAliasInvoker $runtimeAliases;

    /** @var array<int, MiddlewarePipeline> */
    private array $pipelines = [];

    /**
     * @param array<class-string|object|callable|string> $preGlobalRaw
     * @param array<class-string|object|callable|string> $postGlobalRaw
     */
    public function __construct(
        private readonly Invoker $invoker,
        private readonly bool $useInvoker = true,
        private readonly array $preGlobalRaw = [],
        private readonly array $postGlobalRaw = [],
    ) {
        $this->runtimeAliases = new RuntimeAliasInvoker($invoker);
    }

    /**
     * @param array<string,mixed> $vars
     */
    public function dispatch(CompiledRoute $route, Request $request, array $vars): Response
    {
        $request = $this->attachRouteAttributes($route, $request, $vars);

        if ($this->preGlobalRaw === [] && $route->getMiddlewares() === [] && $this->postGlobalRaw === []) {
            return $this->dispatchFinal($route, $request);
        }

        $routeId = $route->getIndex();
        $this->pipelines[$routeId] ??= $this->compilePipelineForRoute(
            $route,
            $this->buildFinalHandler($route),
        );

        return $this->pipelines[$routeId]->handle($request);
    }

    private function aliasStringClass(string $alias): ?string
    {
        $descriptor = MiddlewareAliases::compileString($alias);
        if (is_string($descriptor)) {
            return class_exists($descriptor) ? $descriptor : null;
        }

        return is_string($descriptor->resolver) && class_exists($descriptor->resolver)
            ? $descriptor->resolver
            : null;
    }

    private function assertMiddlewareResponse(mixed $result, string $source): Response
    {
        if (!$result instanceof Response) {
            throw new InvalidArgumentException("Middleware {$source} must return Response.");
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function attachRouteAttributes(CompiledRoute $route, Request $request, array $vars): Request
    {
        $attrs = [
            'route_params' => $vars,
            'route.params' => $vars,
            'params' => $vars,
        ];

        $corsPolicy = $route->getCorsPolicy();
        if ($corsPolicy !== null) {
            $attrs['cors_policy'] = $corsPolicy;
        }

        $produces = $route->getProduces();
        if ($produces !== null) {
            $attrs['produces'] = $produces;
        }

        return $request->withAttributes($attrs);
    }

    /**
     * @return Closure(Request):Response
     */
    private function buildFinalHandler(CompiledRoute $route): Closure
    {
        $handler = $route->getHandler();

        return function (Request $request) use ($handler): Response {
            $routeVars = $this->normalizeNamedArguments($request->getAttribute('route_params', []));
            $result = $this->invokeRouteHandler(
                $handler,
                $routeVars + ['request' => $request],
            );

            return $result instanceof Response
                ? $result
                : Response::json($this->normalizeResponsePayload($result));
        };
    }

    private function buildInvokableFromEntry(mixed $mw): callable
    {
        if (is_callable($mw) && !is_string($mw)) {
            return Closure::fromCallable($mw);
        }
        if (is_string($mw) && $this->looksLikeAliasString($mw)) {
            return $this->wrapAliasStringAsMiddleware($mw);
        }
        if (is_string($mw)) {
            return $this->wrapClassStringAsMiddleware($mw);
        }
        if (is_object($mw)) {
            return $this->wrapObjectAsMiddleware($mw);
        }

        throw new InvalidArgumentException(sprintf('Unsupported middleware entry of type %s', gettype($mw)));
    }

    /**
     * @param array<class-string|object|callable|string|array{0:object|string,1:string}> $list
     * @return list<callable(Request, Closure(Request):Response):Response>
     */
    private function buildInvokables(array $list): array
    {
        $out = [];
        foreach ($list as $mw) {
            $out[] = $this->buildInvokableFromEntry($mw);
        }

        return $out;
    }

    /** @param class-string $class */
    private function canInstantiateWithoutArguments(string $class): bool
    {
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();

        return $ctor === null || array_all($ctor->getParameters(), fn($param) => $param->isOptional());
    }

    /**
     * @return array{0:class-string,1:string}|null
     */
    private function classMethodArrayHandler(mixed $handler): ?array
    {
        if (
            !is_array($handler)
            || count($handler) !== 2
            || !is_string($handler[0])
            || !is_string($handler[1])
            || !class_exists($handler[0])
        ) {
            return null;
        }

        return [$handler[0], $handler[1]];
    }

    /**
     * @param Closure(Request):Response $final
     */
    private function compilePipelineForRoute(CompiledRoute $route, Closure $final): MiddlewarePipeline
    {
        [$preInv, $postInv] = $this->filteredGlobalsFor($route);
        $routeInvokables = $this->buildInvokables($route->getMiddlewares());
        $stack = [...$preInv, ...$routeInvokables, ...$postInv];

        return new MiddlewarePipeline(
            $stack,
            $final,
            invoker: $this->invoker,
            useInvoker: $this->useInvoker,
            invokeFinalWithInvoker: false,
        );
    }

    private function dispatchFinal(CompiledRoute $route, Request $request): Response
    {
        $routeVars = $this->normalizeNamedArguments($request->getAttribute('route_params', []));
        $result = $this->invokeRouteHandler(
            $route->getHandler(),
            $routeVars + ['request' => $request],
        );

        return $result instanceof Response
            ? $result
            : Response::json($this->normalizeResponsePayload($result));
    }

    /**
     * @return array{0:list<callable>,1:list<callable>}
     */
    private function filteredGlobalsFor(CompiledRoute $route): array
    {
        $routeClasses = $this->routeMiddlewareClasses($route);
        if ($routeClasses === []) {
            return [
                $this->buildInvokables($this->preGlobalRaw),
                $this->buildInvokables($this->postGlobalRaw),
            ];
        }

        return [
            $this->buildInvokables($this->filterGlobals($this->preGlobalRaw, $routeClasses)),
            $this->buildInvokables($this->filterGlobals($this->postGlobalRaw, $routeClasses)),
        ];
    }

    /**
     * @param array<class-string|object|callable|string> $globals
     * @param array<string,true> $routeClasses
     * @return array<class-string|object|callable|string>
     */
    private function filterGlobals(array $globals, array $routeClasses): array
    {
        $out = [];
        foreach ($globals as $mw) {
            if ($this->shouldKeepGlobalEntry($mw, $routeClasses)) {
                $out[] = $mw;
            }
        }

        return $out;
    }

    /** @param class-string $class */
    private function instantiateClassViaInterMix(string $class): object
    {
        try {
            $instance = $this->invoker->make($class);
            if (is_object($instance)) {
                return $instance;
            }
        } catch (\Throwable $e) {
            if ($this->canInstantiateWithoutArguments($class)) {
                return new $class();
            }

            throw new InvalidArgumentException("Failed to instantiate middleware class '{$class}'.", 0, $e);
        }

        if ($this->canInstantiateWithoutArguments($class)) {
            return new $class();
        }

        throw new InvalidArgumentException("Failed to instantiate middleware class '{$class}'.");
    }

    private function invokeClassMiddleware(string $class, Request $req, callable $next): Response
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Middleware class '{$class}' not found.");
        }

        $instance = $this->instantiateClassViaInterMix($class);
        if (!is_callable($instance)) {
            throw new InvalidArgumentException("Middleware {$class} must be invokable (__invoke).");
        }

        $callable = $instance(...);

        return $this->assertMiddlewareResponse($callable($req, $next), $class);
    }

    /**
     * @param array<string,mixed> $callArgs
     */
    private function invokeRouteHandler(mixed $handler, array $callArgs): mixed
    {
        $classMethod = $this->classMethodArrayHandler($handler);
        if ($classMethod !== null) {
            if (is_callable($classMethod)) {
                return $this->invoker->invoke($classMethod, $callArgs);
            }

            return $this->invoker->make($classMethod[0], method: $classMethod[1], methodArgs: $callArgs);
        }

        if (is_string($handler)) {
            $resolved = $this->parseClassMethodStringHandler($handler);
            if ($resolved !== null) {
                return $this->invoker->make($resolved[0], method: $resolved[1], methodArgs: $callArgs);
            }
        }

        if (!is_callable($handler)) {
            throw new InvalidArgumentException('Route handler is not callable.');
        }

        return $this->invoker->invoke($handler, $callArgs);
    }

    private function isDirectMiddlewareCallable(mixed $mw): bool
    {
        return is_callable($mw) && !is_string($mw);
    }

    private function looksLikeAliasString(string $value): bool
    {
        if (class_exists($value) || function_exists($value) || (str_contains($value, '::') && is_callable($value))) {
            return false;
        }

        $name = strtolower(trim(explode(':', $value, 2)[0]));

        return $name !== '' && MiddlewareAliases::has($name);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeNamedArguments(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @return array<array-key,mixed>|bool|float|int|JsonSerializable|string|null
     */
    private function normalizeResponsePayload(mixed $value): array|bool|float|int|JsonSerializable|string|null
    {
        if (is_array($value) || $value instanceof JsonSerializable) {
            return $value;
        }
        if ($value === null || is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        return get_debug_type($value);
    }

    /**
     * @return array{0:class-string,1:string}|null
     */
    private function parseClassMethodStringHandler(string $handler): ?array
    {
        if (!str_contains($handler, '::') && !str_contains($handler, '@')) {
            return null;
        }

        [$class, $method] = str_contains($handler, '::')
            ? explode('::', $handler, 2)
            : explode('@', $handler, 2);

        if ($class === '' || $method === '' || !class_exists($class)) {
            return null;
        }

        return [$class, $method];
    }

    /**
     * @return array<string,true>
     */
    private function routeMiddlewareClasses(CompiledRoute $route): array
    {
        $set = [];
        foreach ($route->getMiddlewares() as $mw) {
            if (is_string($mw)) {
                if ($this->looksLikeAliasString($mw) && ($class = $this->aliasStringClass($mw))) {
                    $set[$class] = true;
                } elseif (class_exists($mw)) {
                    $set[$mw] = true;
                }
            } elseif (is_object($mw)) {
                $set[$mw::class] = true;
            }
        }

        return $set;
    }

    /**
     * @param array<string,true> $routeClasses
     */
    private function shouldKeepGlobalEntry(mixed $mw, array $routeClasses): bool
    {
        if (is_string($mw) && class_exists($mw)) {
            return !isset($routeClasses[$mw]);
        }
        if (is_object($mw)) {
            return !isset($routeClasses[$mw::class]);
        }
        if ($this->isDirectMiddlewareCallable($mw)) {
            return true;
        }
        if (is_string($mw) && $this->looksLikeAliasString($mw)) {
            $class = $this->aliasStringClass($mw);

            return $class === null || !isset($routeClasses[$class]);
        }
        if (is_string($mw)) {
            throw new InvalidArgumentException("Middleware class or alias '{$mw}' not found.");
        }

        throw new InvalidArgumentException(sprintf('Unsupported middleware entry of type %s', gettype($mw)));
    }

    private function wrapAliasStringAsMiddleware(string $alias): callable
    {
        $descriptor = MiddlewareAliases::compileString($alias);
        if (is_string($descriptor)) {
            return fn(Request $request, Closure $next): Response => $this->invokeClassMiddleware(
                $descriptor,
                $request,
                $next,
            );
        }

        return fn(Request $request, Closure $next): Response => $this->runtimeAliases->invoke(
            $descriptor,
            $request,
            $next,
            $alias,
        );
    }

    private function wrapClassStringAsMiddleware(string $mw): callable
    {
        if (!class_exists($mw)) {
            throw new InvalidArgumentException("Middleware class '{$mw}' not found.");
        }

        return fn(Request $request, callable $next): Response => $this->invokeClassMiddleware($mw, $request, $next);
    }

    private function wrapObjectAsMiddleware(object $mw): callable
    {
        if (!is_callable($mw)) {
            throw new InvalidArgumentException(sprintf('Middleware object %s is not invokable', $mw::class));
        }

        return fn(Request $request, Closure $next): Response => $this->assertMiddlewareResponse($mw($request, $next), $mw::class);
    }
}
