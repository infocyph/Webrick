<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use InvalidArgumentException;

/**
 * Dispatches a CompiledRoute and composes the middleware pipeline.
 * - Resolves global middleware with route-level overrides (route wins).
 * - Supports alias strings like "throttle:60,60" via MiddlewareAliases.
 * - Avoids instantiating middleware via DI to prevent "Closure is not instantiable!"
 *   and uses DI only at call time (so $next gets injected correctly).
 */
final class Dispatcher
{
    /** @var array<int,MiddlewarePipeline> route-id ⇒ compiled pipeline */
    private array $pipelines = [];

    /** Raw globals (can include class-string|object|callable|alias-string) */
    /** @var array<class-string|object|callable|string> */
    private array $preGlobalRaw;

    /** @var array<class-string|object|callable|string> */
    private array $postGlobalRaw;

    public function __construct(
        private readonly Invoker $invoker,
        private readonly bool $useInvoker = true,
        array $preGlobal = [],
        array $postGlobal = [],
    ) {
        $this->preGlobalRaw  = $preGlobal;
        $this->postGlobalRaw = $postGlobal;
    }

    public function dispatch(
        CompiledRoute $route,
        Request $request,
        array $vars,
    ): Response {
        $request = $request->withAttribute('route_params', $vars);

        if (method_exists($route, 'getCorsPolicy') && $corsPolicy = $route->getCorsPolicy()) {
            $request = $request->withAttribute('cors_policy', $corsPolicy);
        }

        $invoker = $this->invoker;
        $final = static function (Request $req) use ($route, $vars, $invoker): Response {
            $result = $invoker->invoke($route->getHandler(), $vars);
            return $result instanceof Response ? $result : Response::json($result);
        };

        $routeId = $route->getIndex();

        // Build + memoize the full pipeline (globals + route + globals) per route
        $this->pipelines[$routeId] ??= $this->compilePipelineForRoute($route, $final);

        return $this->pipelines[$routeId]->handle($request);
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    private function compilePipelineForRoute(CompiledRoute $route, Closure $final): MiddlewarePipeline
    {
        // Compute (and filter) global stacks for this route
        [$preInv, $postInv] = $this->filteredGlobalsFor($route);

        // Route-level stack (also support alias strings here)
        $routeInvokables = $this->buildInvokables($route->getMiddlewares());

        // Merge pre → route → post
        $stack = [...$preInv, ...$routeInvokables, ...$postInv];

        return new MiddlewarePipeline($stack, $final, invoker: $this->invoker, useInvoker: $this->useInvoker);
    }

    /**
     * Compute filtered global stacks for a specific route:
     *  • if a middleware class is present on the route, drop the same class from globals
     *  • alias strings in globals are resolved to classes so we can compare fairly
     *
     * @return array{0:list<callable>,1:list<callable>}
     */
    private function filteredGlobalsFor(CompiledRoute $route): array
    {
        $routeClasses = $this->routeMiddlewareClasses($route);

        $preRaw  = $this->filterGlobals($this->preGlobalRaw, $routeClasses);
        $postRaw = $this->filterGlobals($this->postGlobalRaw, $routeClasses);

        $preInv  = $this->buildInvokables($preRaw);
        $postInv = $this->buildInvokables($postRaw);

        return [$preInv, $postInv];
    }

    /** @return array<string,true> */
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
     * Remove any global middleware whose class matches a route middleware class.
     * Also resolves alias strings in globals (e.g. 'throttle:60,60') to their class
     * to compare fairly and let the route override.
     *
     * @param array<class-string|object|callable|string> $globals
     * @param array<string,true> $routeClasses
     * @return array<class-string|object|callable|string>
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

            // object
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

            // alias string?
            if (\is_string($mw) && $this->looksLikeAliasString($mw)) {
                $cls = $this->aliasStringClass($mw); // null if resolver returns plain callable
                if ($cls !== null && isset($routeClasses[$cls])) {
                    continue; // route overrides this global alias
                }
                $out[] = $mw;
                continue;
            }

            // unknown string
            if (\is_string($mw)) {
                throw new InvalidArgumentException("Middleware class or alias '{$mw}' not found.");
            }

            throw new InvalidArgumentException(
                \sprintf('Unsupported middleware entry of type %s', \gettype($mw)),
            );
        }
        return $out;
    }

    /**
     * Turn a raw list (class|object|callable|alias string) into invokable middlewares.
     *
     * @param array<class-string|object|callable|string> $list
     * @return list<callable(Request, Closure(Request):Response):Response>
     */
    private function buildInvokables(array $list): array
    {
        $out = [];

        foreach ($list as $mw) {
            // Already a callable (non-string)
            if (\is_callable($mw) && !\is_string($mw)) {
                $out[] = $mw(...);
                continue;
            }

            // Alias string like "throttle:60,60"
            if (\is_string($mw) && $this->looksLikeAliasString($mw)) {
                $out[] = $this->wrapAliasStringAsMiddleware($mw);
                continue;
            }

            // Class string → do NOT use DI to instantiate (see container error); lazy single instance.
            if (\is_string($mw)) {
                if (!\class_exists($mw)) {
                    throw new InvalidArgumentException("Middleware class '{$mw}' not found.");
                }
                $out[] = static function (Request $req, callable $next) use ($mw): Response {
                    static $instance = null;         // one per process
                    $instance ??= new $mw();         // ← avoid $invoker->make($mw)
                    if (!\is_callable($instance)) {
                        throw new InvalidArgumentException("Middleware {$mw} must be invokable (__invoke).");
                    }
                    return $instance($req, $next);
                };
                continue;
            }

            // Object instance (must be invokable)
            if (\is_object($mw)) {
                if (!\is_callable($mw)) {
                    throw new InvalidArgumentException(
                        \sprintf('Middleware object %s is not invokable', $mw::class),
                    );
                }
                $out[] = static fn (Request $req, Closure $next) => $mw($req, $next);
                continue;
            }

            throw new InvalidArgumentException(
                \sprintf('Unsupported middleware entry of type %s', \gettype($mw)),
            );
        }

        return $out;
    }

    /* -------------------- alias helpers -------------------- */

    private function looksLikeAliasString(string $s): bool
    {
        if (\class_exists($s)) {
            return false;
        } // it's a class-string, not an alias
        $name = \strtolower(\trim(\explode(':', $s, 2)[0] ?? ''));
        return $name !== '' && MiddlewareAliases::has($name);
    }

    /**
     * Extract the class-string behind an alias string, if resolvable to a class/object.
     * Returns null if the alias resolves to a plain callable (no class match to compare).
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
        return null; // callable or unexpected shape
    }

    /**
     * Turn an alias string (e.g. "throttle:60,60") into a middleware wrapper.
     * Resolve once lazily and reuse the same instance per process.
     */
    private function wrapAliasStringAsMiddleware(string $alias): callable
    {
        return static function (Request $req, Closure $next) use ($alias): Response {
            static $resolved = null; // one instance per-process
            $resolved ??= MiddlewareAliases::resolveString($alias);

            if (\is_string($resolved)) {
                // class-string → lazy single instance
                static $obj = null;
                $obj ??= new $resolved();
                if (!\is_callable($obj)) {
                    throw new InvalidArgumentException("Middleware {$resolved} must be invokable (__invoke).");
                }
                return $obj($req, $next);
            }

            if (\is_object($resolved)) {
                if (!\is_callable($resolved)) {
                    throw new InvalidArgumentException(
                        "Resolved middleware object (" . $resolved::class . ') is not invokable.',
                    );
                }
                return $resolved($req, $next);
            }

            // Callable (no class) – just call it (Invoker will still inject request/next if enabled)
            if (\is_callable($resolved)) {
                return $resolved($req, $next);
            }

            throw new InvalidArgumentException("Failed to resolve middleware alias '{$alias}'.");
        };
    }
}
