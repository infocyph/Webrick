<?php

declare(strict_types=1);

/**
 * Dispatcher
 *
 * Responsible for dispatching a CompiledRoute by composing and executing the
 * middleware pipeline. The dispatcher:
 *  - Merges global (pre/post) middleware with route-level middleware,
 *    applying route-level overrides when classes/aliases collide.
 *  - Supports middleware alias strings (e.g. "throttle:60,60") via
 *    MiddlewareAliases and resolves them lazily.
 *  - Resolves middleware classes through InterMix so constructor DI/lifetimes
 *    are respected while still deferring resolution to call-time.
 */

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
 *
 * Responsibilities:
 *  - Compose the middleware pipeline (pre globals → route → post globals).
 *  - Filter global middleware when the route provides an overriding entry.
 *  - Convert raw middleware specifications (class-string, object, callable,
 *    alias-string) into invokable middleware callables suitable for pipeline.
 *  - Memoize compiled pipelines per route index for performance.
 *
 * Notes:
 *  - Middleware alias resolution is delegated to MiddlewareAliases.
 *  - The Invoker may be used to call the final handler and for DI at call-time.
 */
final class Dispatcher
{
    /**
     * Memoized compiled pipelines by route index.
     *
     * @var array<int, MiddlewarePipeline>
     */
    private array $pipelines = [];

    /** @var array<string, callable|object|string> */
    private array $resolvedAliases = [];

    /**
     * Construct the Dispatcher.
     *
     * @param Invoker $invoker DI invoker used to call handlers and for optional injection
     * @param bool $useInvoker Whether to use the invoker when invoking middleware/handlers
     * @param array<class-string|object|callable|string> $preGlobalRaw Prepend global middleware list
     * @param array<class-string|object|callable|string> $postGlobalRaw Append global middleware list
     */
    public function __construct(
        private readonly Invoker $invoker,
        private readonly bool $useInvoker = true,
        /**
         * Raw global "pre" middleware entries.
         *
         * Entries may be:
         *  - class-string (e.g. SomeMiddleware::class)
         *  - instantiated middleware object (object)
         *  - callable(non-string)
         *  - alias string (e.g. 'throttle:60,60')
         */
        private readonly array $preGlobalRaw = [],
        /**
         * Raw global "post" middleware entries (same shapes as preGlobalRaw).
         */
        private readonly array $postGlobalRaw = [],
    ) {}

    /**
     * Dispatch a compiled route with a request and extracted route variables.
     *
     * Workflow:
     *  - Attach route params and optional CORS policy to the request attributes.
     *  - Build or reuse a compiled middleware pipeline for the target route.
     *  - Execute the pipeline and return a Response.
     *
     * @param CompiledRoute $route Compiled route descriptor
     * @param Request $request Incoming request
     * @param array<string,mixed> $vars Extracted route variables (name => value)
     * @return Response Response produced by the pipeline/handler
     */
    public function dispatch(
        CompiledRoute $route,
        Request $request,
        array $vars,
    ): Response {
        $request = $this->attachRouteAttributes($route, $request, $vars);

        if ($this->preGlobalRaw === [] && $route->getMiddlewares() === [] && $this->postGlobalRaw === []) {
            return $this->dispatchFinal($route, $request);
        }

        $routeId = $route->getIndex();

        // Build + memoize the full pipeline (globals + route + globals) per route.
        $this->pipelines[$routeId] ??= $this->compilePipelineForRoute(
            $route,
            $this->buildFinalHandler($route),
        );

        return $this->pipelines[$routeId]->handle($request);
    }

    /**
     * Extract the class-string (if any) that an alias string resolves to.
     *
     * Returns the resolved class-string when the alias maps to a class or an
     * instantiated object; returns null when the alias resolves to a plain
     * callable (no class to compare).
     *
     * @param string $alias Alias string
     * @return string|null Resolved class-string or null if none applicable
     */
    private function aliasStringClass(string $alias): ?string
    {
        $resolved = $this->resolveAlias($alias);

        if (\is_string($resolved)) {
            return \class_exists($resolved) ? $resolved : null;
        }

        if (\is_object($resolved)) {
            return $resolved::class;
        }

        // Callable or unexpected shape — no comparable class.
        return null;
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
        if ($corsPolicy) {
            $attrs['cors_policy'] = $corsPolicy;
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
        if (\is_callable($mw) && !\is_string($mw)) {
            return \Closure::fromCallable($mw);
        }

        if (\is_string($mw) && $this->looksLikeAliasString($mw)) {
            return $this->wrapAliasStringAsMiddleware($mw);
        }

        if (\is_string($mw)) {
            return $this->wrapClassStringAsMiddleware($mw);
        }

        if (\is_object($mw)) {
            return $this->wrapObjectAsMiddleware($mw);
        }

        throw new InvalidArgumentException(
            \sprintf('Unsupported middleware entry of type %s', \gettype($mw)),
        );
    }

    /**
     * Turn a raw list (class|object|callable|alias string) into invokable middleware callables.
     *
     * Each returned callable conforms to: function(Request $req, callable $next): Response
     *
     * @param array<class-string|object|callable|string> $list Raw middleware descriptors
     * @return list<callable(Request, Closure(Request):Response):Response> List of invokable middleware callables
     *
     * @throws InvalidArgumentException When middleware entries reference missing classes
     *                                  or are not invokable objects.
     */
    private function buildInvokables(array $list): array
    {
        $out = [];

        foreach ($list as $mw) {
            $out[] = $this->buildInvokableFromEntry($mw);
        }

        return $out;
    }

    /**
     * Check whether a class can be safely constructed with zero arguments.
     */
    /**
     * @param class-string $class
     */
    private function canInstantiateWithoutArguments(string $class): bool
    {
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return true;
        }

        return array_all($ctor->getParameters(), fn($param) => $param->isOptional());
    }

    /**
     * @return array{0:class-string,1:string}|null
     */
    private function classMethodArrayHandler(mixed $handler): ?array
    {
        if (
            !\is_array($handler)
            || \count($handler) !== 2
            || !\is_string($handler[0])
            || !\is_string($handler[1])
            || !\class_exists($handler[0])
        ) {
            return null;
        }

        return [$handler[0], $handler[1]];
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Compile a MiddlewarePipeline for a given route and terminal handler.
     *
     * @param CompiledRoute $route Target compiled route
     * @param Closure(Request):Response $final Final handler that returns a Response
     * @return MiddlewarePipeline Compiled pipeline ready for handling requests
     */
    private function compilePipelineForRoute(CompiledRoute $route, Closure $final): MiddlewarePipeline
    {
        // Compute filtered global stacks (route may override globals).
        [$preInv, $postInv] = $this->filteredGlobalsFor($route);

        // Convert route-level middleware into invokable callables.
        $routeInvokables = $this->buildInvokables($route->getMiddlewares());

        // Merge pre → route → post into the final stack.
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
     * Compute filtered global middleware stacks for a specific route.
     *
     * Rules:
     *  - If a middleware class is present on the route, that class is removed
     *    from the global stacks so the route-level definition wins.
     *  - Alias strings in globals are resolved to classes where possible to
     *    allow fair comparison against route entries.
     *
     * @param CompiledRoute $route Compiled route
     * @return array{0:list<callable>,1:list<callable>} Tuple [preInvokables, postInvokables]
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

        $preRaw = $this->filterGlobals($this->preGlobalRaw, $routeClasses);
        $postRaw = $this->filterGlobals($this->postGlobalRaw, $routeClasses);

        $preInv = $this->buildInvokables($preRaw);
        $postInv = $this->buildInvokables($postRaw);

        return [$preInv, $postInv];
    }

    /**
     * Remove any global middleware whose class matches a route middleware class.
     *
     * Additionally resolves alias strings in globals (e.g. 'throttle:60,60') to
     * their class so a route override can be detected.
     *
     * @param array<class-string|object|callable|string> $globals Raw global middleware entries
     * @param array<string,true> $routeClasses Set of route middleware classes
     * @return array<class-string|object|callable|string> Filtered globals preserving original shapes
     *
     * @throws InvalidArgumentException When encountering an unknown string or unsupported entry type
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

    /**
     * Build a class instance via InterMix without auto-invoking __invoke.
     *
     * InterMix's generic class make/callable paths may auto-call __invoke for
     * invokable classes. For middleware we only want constructor DI here.
     *
     * @param class-string $class
     */
    private function instantiateClassViaInterMix(string $class): object
    {
        try {
            $instance = $this->invoker->make($class);
            if (\is_object($instance)) {
                return $instance;
            }
        } catch (\Throwable $e) {
            // Some middleware constructors intentionally rely on optional
            // interface-typed params with scalar defaults. Fall back to
            // argument-less construction when that is valid.
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
        if (!\class_exists($class)) {
            throw new InvalidArgumentException("Middleware class '{$class}' not found.");
        }

        $instance = $this->instantiateClassViaInterMix($class);
        if (!\is_callable($instance)) {
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
            if (\is_callable($classMethod)) {
                return $this->invoker->invoke($classMethod, $callArgs);
            }

            return $this->invoker->make($classMethod[0], method: $classMethod[1], methodArgs: $callArgs);
        }

        if (\is_string($handler)) {
            $resolved = $this->parseClassMethodStringHandler($handler);
            if ($resolved !== null) {
                return $this->invoker->make($resolved[0], method: $resolved[1], methodArgs: $callArgs);
            }
        }

        if (!\is_callable($handler)) {
            throw new InvalidArgumentException('Route handler is not callable.');
        }

        return $this->invoker->invoke($handler, $callArgs);
    }

    private function isDirectMiddlewareCallable(mixed $mw): bool
    {
        return \is_callable($mw) && !\is_string($mw);
    }

    /* -------------------- alias helpers -------------------- */

    /**
     * Heuristic: determine if a string looks like a middleware alias rather than a class-string.
     *
     * Rules:
     *  - If class_exists($s) returns true it's considered a class-string, not an alias.
     *  - Otherwise, extract the name part before ':' and check MiddlewareAliases::has(name).
     *
     * @param string $s Candidate string
     * @return bool True when $s appears to be a registered alias string
     */
    private function looksLikeAliasString(string $s): bool
    {
        if (\class_exists($s) || \function_exists($s) || (\str_contains($s, '::') && \is_callable($s))) {
            return false;
        }

        $name = \strtolower(\trim(\explode(':', $s, 2)[0]));

        return $name !== '' && MiddlewareAliases::has($name);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeNamedArguments(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $k => $v) {
            if (\is_string($k)) {
                $normalized[$k] = $v;
            }
        }

        return $normalized;
    }

    /**
     * @return array<array-key,mixed>|bool|float|int|JsonSerializable|string|null
     */
    private function normalizeResponsePayload(mixed $value): array|bool|float|int|JsonSerializable|string|null
    {
        if (\is_array($value)) {
            return $value;
        }

        if ($value instanceof JsonSerializable) {
            return $value;
        }

        if (
            $value === null
            || \is_bool($value)
            || \is_float($value)
            || \is_int($value)
            || \is_string($value)
        ) {
            return $value;
        }

        return \get_debug_type($value);
    }

    /**
     * @return array{0:class-string,1:string}|null
     */
    private function parseClassMethodStringHandler(string $handler): ?array
    {
        if (!\str_contains($handler, '::') && !\str_contains($handler, '@')) {
            return null;
        }

        [$class, $method] = \str_contains($handler, '::')
            ? \explode('::', $handler, 2)
            : \explode('@', $handler, 2);

        if ($class === '' || $method === '' || !\class_exists($class)) {
            return null;
        }

        return [$class, $method];
    }

    /**
     * Resolve each alias descriptor once for this kernel. Both global-override
     * inspection and pipeline execution use the same middleware instance.
     */
    private function resolveAlias(string $alias): callable|object|string
    {
        return $this->resolvedAliases[$alias] ??= MiddlewareAliases::resolveString($alias);
    }

    /**
     * Collect the set of middleware class-strings referenced by the route.
     *
     * This inspects route middleware entries and returns a set of class names
     * so globals can be filtered when a route provides an explicit replacement.
     *
     * @param CompiledRoute $route Compiled route
     * @return array<string,true> Map of class-string => true
     */
    private function routeMiddlewareClasses(CompiledRoute $route): array
    {
        $set = [];
        foreach ($route->getMiddlewares() as $mw) {
            if (\is_string($mw)) {
                if ($this->looksLikeAliasString($mw) && ($cls = $this->aliasStringClass($mw))) {
                    $set[$cls] = true;
                } elseif (\class_exists($mw)) {
                    $set[$mw] = true;
                }
            } elseif (\is_object($mw)) {
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
        if (\is_string($mw) && \class_exists($mw)) {
            return !isset($routeClasses[$mw]);
        }

        if (\is_object($mw)) {
            return !isset($routeClasses[$mw::class]);
        }

        if ($this->isDirectMiddlewareCallable($mw)) {
            return true;
        }

        if (\is_string($mw) && $this->looksLikeAliasString($mw)) {
            $cls = $this->aliasStringClass($mw);

            return $cls === null || !isset($routeClasses[$cls]);
        }

        if (\is_string($mw)) {
            throw new InvalidArgumentException("Middleware class or alias '{$mw}' not found.");
        }

        throw new InvalidArgumentException(
            \sprintf('Unsupported middleware entry of type %s', \gettype($mw)),
        );
    }

    /**
     * Convert an alias string (e.g. "throttle:60,60") into a middleware wrapper.
     *
     * The wrapper resolves the alias once lazily and reuses the resolved value
     * (class-string → single instance, object, or callable) for subsequent calls.
     *
     * @param string $alias Alias specification
     * @return callable(Request, Closure(Request):Response):Response Middleware wrapper callable
     */
    private function wrapAliasStringAsMiddleware(string $alias): callable
    {
        return function (Request $req, Closure $next) use ($alias): Response {
            $resolved = $this->resolveAlias($alias);

            if (\is_string($resolved)) {
                return $this->invokeClassMiddleware($resolved, $req, $next);
            }

            if (\is_object($resolved)) {
                if (!\is_callable($resolved)) {
                    throw new InvalidArgumentException(
                        'Resolved middleware object (' . $resolved::class . ') is not invokable.',
                    );
                }

                return $this->assertMiddlewareResponse($resolved($req, $next), $resolved::class);
            }

            // The declared resolver contract leaves only a callable here.
            return $this->assertMiddlewareResponse($resolved($req, $next), $alias);
        };
    }

    private function wrapClassStringAsMiddleware(string $mw): callable
    {
        if (!\class_exists($mw)) {
            throw new InvalidArgumentException("Middleware class '{$mw}' not found.");
        }

        return fn(Request $req, callable $next): Response => $this->invokeClassMiddleware($mw, $req, $next);
    }

    private function wrapObjectAsMiddleware(object $mw): callable
    {
        if (!\is_callable($mw)) {
            throw new InvalidArgumentException(
                \sprintf('Middleware object %s is not invokable', $mw::class),
            );
        }

        return fn(Request $req, Closure $next): Response => $this->assertMiddlewareResponse($mw($req, $next), $mw::class);
    }
}
