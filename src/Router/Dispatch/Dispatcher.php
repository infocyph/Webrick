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
 *
 * @package Infocyph\Webrick\Router\Dispatch
 */

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use InvalidArgumentException;

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

    /**
     * Raw global "post" middleware entries (same shapes as preGlobalRaw).
     *
     * @var array<class-string|object|callable|string>
     */
    private array $postGlobalRaw;

    /**
     * Raw global "pre" middleware entries.
     *
     * Entries may be:
     *  - class-string (e.g. SomeMiddleware::class)
     *  - instantiated middleware object (object)
     *  - callable (non-string)
     *  - alias string (e.g. 'throttle:60,60')
     *
     * @var array<class-string|object|callable|string>
     */
    private array $preGlobalRaw;

    /**
     * Construct the Dispatcher.
     *
     * @param Invoker $invoker DI invoker used to call handlers and for optional injection
     * @param bool $useInvoker Whether to use the invoker when invoking middleware/handlers
     * @param array<class-string|object|callable|string> $preGlobal Prepend global middleware list
     * @param array<class-string|object|callable|string> $postGlobal Append global middleware list
     */
    public function __construct(
        private readonly Invoker $invoker,
        private readonly bool $useInvoker = true,
        array $preGlobal = [],
        array $postGlobal = [],
    ) {
        $this->preGlobalRaw = $preGlobal;
        $this->postGlobalRaw = $postGlobal;
    }

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
        // Attach route metadata using a single clone.
        $attrs = [
            'route_params' => $vars,
            'route.params' => $vars,
            'params' => $vars,
        ];
        if (method_exists($route, 'getCorsPolicy') && $corsPolicy = $route->getCorsPolicy()) {
            $attrs['cors_policy'] = $corsPolicy;
        }
        $request = $request->withAttributes($attrs);

        $invoker = $this->invoker;

        // Final handler closure: invoke the route handler via Invoker and normalize to Response.
        $final = static function (Request $req) use ($route, $invoker): Response {
            $handler = $route->getHandler();
            $routeVars = $req->getAttribute('route_params', []);
            if (!\is_array($routeVars)) {
                $routeVars = [];
            }
            // Keep route vars first so numeric fallback mapping for untyped
            // parameters remains stable, then add request for cache key variance.
            $callArgs = $routeVars + ['request' => $req];

            // Avoid stale return-value reuse on class-method handlers by invoking
            // the method against a resolved instance callable per request.
            if (\is_array($handler)
                && \count($handler) === 2
                && \is_string($handler[0])
                && \is_string($handler[1])
            ) {
                $result = $invoker->make(
                    $handler[0],
                    method: $handler[1],
                    methodArgs: $callArgs,
                );
            } elseif (\is_string($handler) && (\str_contains($handler, '::') || \str_contains($handler, '@'))) {
                [$class, $method] = \str_contains($handler, '::')
                    ? \explode('::', $handler, 2)
                    : \explode('@', $handler, 2);

                if ($class !== '' && $method !== '' && \class_exists($class)) {
                    $result = $invoker->make($class, method: $method, methodArgs: $callArgs);
                } else {
                    $result = $invoker->invoke($handler, $callArgs);
                }
            } else {
                $result = $invoker->invoke($handler, $callArgs);
            }

            // Normalize non-Response results into a JSON response.
            return $result instanceof Response ? $result : Response::json($result);
        };

        $routeId = $route->getIndex();

        // Build + memoize the full pipeline (globals + route + globals) per route.
        $this->pipelines[$routeId] ??= $this->compilePipelineForRoute($route, $final);

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
        $resolved = MiddlewareAliases::resolveString($alias);

        if (\is_string($resolved)) {
            return \class_exists($resolved) ? $resolved : null;
        }

        if (\is_object($resolved)) {
            return $resolved::class;
        }

        // Callable or unexpected shape — no comparable class.
        return null;
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
            // Already a callable (but not a string) — use variadic wrapper to preserve signature.
            if (\is_callable($mw) && !\is_string($mw)) {
                $out[] = $mw(...);
                continue;
            }

            // Alias string like "throttle:60,60" — wrap into a lazily-resolved middleware.
            if (\is_string($mw) && $this->looksLikeAliasString($mw)) {
                $out[] = $this->wrapAliasStringAsMiddleware($mw);
                continue;
            }

            // Class string — resolve via InterMix (constructor DI + container lifetime).
            if (\is_string($mw)) {
                if (!\class_exists($mw)) {
                    throw new InvalidArgumentException("Middleware class '{$mw}' not found.");
                }
                $out[] = function (Request $req, callable $next) use ($mw): Response {
                    $instance = $this->instantiateClassViaInterMix($mw);
                    if (!\is_callable($instance)) {
                        throw new InvalidArgumentException("Middleware {$mw} must be invokable (__invoke).");
                    }

                    $callable = $instance(...);
                    return $callable($req, $next);
                };
                continue;
            }

            // Object instance — must be invokable.
            if (\is_object($mw)) {
                if (!\is_callable($mw)) {
                    throw new InvalidArgumentException(
                        \sprintf('Middleware object %s is not invokable', $mw::class),
                    );
                }
                // Preserve object invocation semantics.
                $out[] = static fn (Request $req, Closure $next) => $mw($req, $next);
                continue;
            }

            // Anything else is unsupported.
            throw new InvalidArgumentException(
                \sprintf('Unsupported middleware entry of type %s', \gettype($mw)),
            );
        }

        return $out;
    }

    /**
     * Check whether a class can be safely constructed with zero arguments.
     */
    private function canInstantiateWithoutArguments(string $class): bool
    {
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return true;
        }

        foreach ($ctor->getParameters() as $param) {
            if (!$param->isOptional()) {
                return false;
            }
        }

        return true;
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

        return new MiddlewarePipeline($stack, $final, invoker: $this->invoker, useInvoker: $this->useInvoker);
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
            // class-string
            if (\is_string($mw) && \class_exists($mw)) {
                if (isset($routeClasses[$mw])) {
                    continue;
                }
                $out[] = $mw;
                continue;
            }

            // object instance
            if (\is_object($mw)) {
                if (isset($routeClasses[$mw::class])) {
                    continue;
                }
                $out[] = $mw;
                continue;
            }

            // callable (non-string)
            if (\is_callable($mw) && !\is_string($mw)) {
                $out[] = $mw;
                continue;
            }

            // alias string like "throttle:60,60"
            if (\is_string($mw) && $this->looksLikeAliasString($mw)) {
                $cls = $this->aliasStringClass($mw); // null if resolver returns plain callable
                if ($cls !== null && isset($routeClasses[$cls])) {
                    // Route overrides this global alias.
                    continue;
                }
                $out[] = $mw;
                continue;
            }

            // plain string that is not a recognised alias or class
            if (\is_string($mw)) {
                throw new InvalidArgumentException("Middleware class or alias '{$mw}' not found.");
            }

            // Any other type is unsupported.
            throw new InvalidArgumentException(
                \sprintf('Unsupported middleware entry of type %s', \gettype($mw)),
            );
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
     * @return object
     */
    private function instantiateClassViaInterMix(string $class): object
    {
        try {
            $resolver = $this->invoker->getContainer()->getCurrentResolver();
            $settled = match (true) {
                $resolver instanceof InjectedCall => $resolver->classSettler($class, '__webrick_noop__', true),
                $resolver instanceof GenericCall => $resolver->classSettler($class, '__webrick_noop__'),
                default => throw new InvalidArgumentException(
                    \sprintf('Unsupported InterMix resolver type: %s', $resolver::class),
                ),
            };

            $instance = \is_array($settled) ? ($settled['instance'] ?? null) : null;
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
        if (\class_exists($s)) {
            return false;
        } // it's a class-string, not an alias

        $name = \strtolower(\trim(\explode(':', $s, 2)[0] ?? ''));

        return $name !== '' && MiddlewareAliases::has($name);
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
                if (\class_exists($mw)) {
                    $set[$mw] = true;
                } elseif ($this->looksLikeAliasString($mw) && ($cls = $this->aliasStringClass($mw))) {
                    $set[$cls] = true;
                }
            } elseif (\is_object($mw)) {
                $set[$mw::class] = true;
            }
        }
        return $set;
    }

    /**
     * Convert an alias string (e.g. "throttle:60,60") into a middleware wrapper.
     *
     * The wrapper resolves the alias once lazily and reuses the resolved value
     * (class-string → single instance, object, or callable) for subsequent calls.
     *
     * @param string $alias Alias specification
     * @return callable(Request, Closure(Request):Response):Response Middleware wrapper callable
     *
     * @throws InvalidArgumentException When resolved middleware is not invokable
     */
    private function wrapAliasStringAsMiddleware(string $alias): callable
    {
        return function (Request $req, Closure $next) use ($alias): Response {
            static $resolved = null; // cached resolution per-process
            $resolved ??= MiddlewareAliases::resolveString($alias);

            if (\is_string($resolved)) {
                if (!\class_exists($resolved)) {
                    throw new InvalidArgumentException("Middleware class '{$resolved}' not found.");
                }

                $instance = $this->instantiateClassViaInterMix($resolved);
                if (!\is_callable($instance)) {
                    throw new InvalidArgumentException("Middleware {$resolved} must be invokable (__invoke).");
                }

                $callable = $instance(...);
                return $callable($req, $next);
            }

            if (\is_object($resolved)) {
                if (!\is_callable($resolved)) {
                    throw new InvalidArgumentException(
                        "Resolved middleware object (" . $resolved::class . ') is not invokable.',
                    );
                }
                return $resolved($req, $next);
            }

            // Callable (closure or function) — invoke directly.
            if (\is_callable($resolved)) {
                return $resolved($req, $next);
            }

            throw new InvalidArgumentException("Failed to resolve middleware alias '{$alias}'.");
        };
    }
}
