<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};

/**
 * Glues together compiler → matcher → dispatcher.
 *
 * Supports three middleware rings:
 *   1) Pre-route (global)       – runs on request entry
 *   2) Per-route (existing)     – attached on routes, executed by Dispatcher
 *   3) Post-controller (global) – wraps the dispatch result before emit
 *
 * If ErrorHandlerMiddleware is present in pre/post rings, exceptions bubble to it.
 * Otherwise, this kernel provides a minimal JSON fallback for 404/405/500.
 */
final class RouterKernel
{
    /** @var Closure():list<CompiledRoute> */
    private Closure $compiler;

    /** @var list<callable(Request, Closure(Request):Response):Response> */
    private array $preGlobal = [];

    /** @var list<callable(Request, Closure(Request):Response):Response> */
    private array $postGlobal = [];

    /** True when an ErrorHandlerMiddleware is present in pre/post globals. */
    private bool $hasErrorHandler = false;

    public function __construct(
        private MatcherInterface $matcher,
        private readonly Dispatcher $dispatcher,
        Closure $compiler,
        private readonly LoggerInterface $log,
        array $preGlobal = [],
        array $postGlobal = [],
    ) {
        $this->compiler = $compiler;
        $this->warm();

        // Detect error handler BEFORE normalization (we can see class-strings/instances here)
        $this->hasErrorHandler =
            $this->detectErrorHandler($preGlobal) || $this->detectErrorHandler($postGlobal);

        // Accept objects, class-strings, or callables
        $this->preGlobal  = $this->normalizeGlobalMiddleware($preGlobal);
        $this->postGlobal = $this->normalizeGlobalMiddleware($postGlobal);
    }

    /*──────────────── bootstrap helper ────────────────*/

    /**
     * @param string|null $routeCache Dir for UnifiedMatcher **or** file for MergedMatcher
     * @param bool $useInvokerForRoute When true, per-route middleware/handler are called via DI Invoker.
     *                                 When false, per-route middleware are called manually ($mw($req, $next)).
     */
    public static function boot(
        LoggerInterface $log,
        Closure $compiler,
        MatcherInterface $matcher,
        ?string $routeCache = null,
        array $preGlobal = [],
        array $postGlobal = [],
        bool $useInvokerForRoute = true,
    ): self {
        if ($routeCache) {
            $matcher->enableCache($routeCache);
        }

        // Pass the toggle down to Dispatcher (BC: default true).
        $dispatcher = new Dispatcher(Invoker::shared(), $useInvokerForRoute);

        return new self($matcher, $dispatcher, $compiler, $log, $preGlobal, $postGlobal);
    }

    /*──────────────── request entry ───────────────────*/

    public function handle(Request $request): Response
    {
        // Bind Request for DI across pre/per-route/post rings
        $defs = Invoker::shared()->getContainer()->definitions();
        $defs->bind(Request::class, $request);

        // Core: match → dispatch. If an ErrorHandlerMiddleware is present,
        // let exceptions bubble; otherwise provide a safe JSON fallback.
        $dispatchCore = $this->buildDispatchCore($this->hasErrorHandler);

        // Wrap core with post-global middleware
        $withPost = $this->composePipeline($this->postGlobal, $dispatchCore);

        // Wrap with pre-global middleware, then execute
        $withPre  = $this->composePipeline($this->preGlobal, $withPost);

        return $withPre($request);
    }

    private function buildDispatchCore(bool $bubbleExceptions): Closure
    {
        if ($bubbleExceptions) {
            return function (Request $req): Response {
                [$route, $vars] = $this->matchRoute($req);
                return $this->dispatcher->dispatch($route, $req, $vars);
            };
        }

        return function (Request $req): Response {
            try {
                [$route, $vars] = $this->matchRoute($req);
                return $this->dispatcher->dispatch($route, $req, $vars);
            } catch (MethodNotAllowedException $e) {
                return Response::json(
                    ['error' => 'Method Not Allowed'],
                    405,
                    ['Allow' => implode(', ', $e->allowed)],
                );
            } catch (RouteNotFoundException) {
                return Response::json(['error' => 'Not Found'], 404);
            } catch (\Throwable $e) {
                $this->log->error('[router] uncaught exception', ['exception' => $e]);
                return Response::json(['error' => 'Server Error'], 500);
            }
        };
    }

    /**
     * @return array{CompiledRoute, array} [route, vars]
     */
    private function matchRoute(Request $req): array
    {
        $method = strtoupper($req->getMethod());
        $uri    = $req->getUri();
        $host   = self::normaliseHost($uri->getHost());
        $path   = $uri->getPath() ?: '/';

        return $this->matcher->match($method, $host, $path);
    }

    /*──────────────── warm-up / cache prime ──────────*/

    private function warm(): void
    {
        $canBootFromCache = \method_exists($this->matcher, 'canBootFromCache')
            && (bool)$this->matcher->canBootFromCache();

        if ($canBootFromCache) {
            if (\method_exists($this->matcher, 'finalize')) {
                $this->matcher->finalize(); // harmless no-op if already final
            }
            $this->log->info('[router] route table ready (hot cache)', [
                'count' => null,
                'matcher' => $this->matcher::class,
                'cache' => true,
                'mode' => 'cache',
            ]);
            return;
        }

        $routes = ($this->compiler)();
        if ($routes === []) {
            throw new \RuntimeException('Route compiler produced an empty table.');
        }

        foreach ($routes as $r) {
            $this->matcher->add($r);
        }

        if (\method_exists($this->matcher, 'finalize')) {
            $this->matcher->finalize();
        }

        $this->log->info('[router] route table ready', [
            'count' => count($routes),
            'matcher' => $this->matcher::class,
            'cache' => \method_exists($this->matcher, 'enableCache'),
            'mode' => 'compiled',
        ]);
    }

    /*──────────────── helpers ────────────────────────*/

    private static function normaliseHost(string $raw): string
    {
        if ($raw === '' || preg_match('/[\x00-\x20]/', $raw)) {
            throw new \InvalidArgumentException('Illegal Host header.');
        }
        $host = rtrim(strtolower($raw), '.');

        if (function_exists('idn_to_ascii') && !str_contains($host, 'xn--')) {
            $ascii = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException('Invalid IDN host name.');
            }
            $host = $ascii;
        }
        if (!preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException('Host contains non-ASCII bytes.');
        }
        return $host;
    }

    /** Detect presence of ErrorHandlerMiddleware by class-string or instance. */
    private function detectErrorHandler(array $list): bool
    {
        $fqcn = '\\Infocyph\\Webrick\\Middleware\\ErrorHandlerMiddleware';
        foreach ($list as $mw) {
            if (is_string($mw) && $mw === $fqcn) {
                return true;
            }
            if (is_object($mw) && is_a($mw, $fqcn)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize global middleware entries:
     *  - invokable objects / closures / callables
     *  - class-strings (instantiated directly; if you need DI, pass an object)
     *
     * @param array<class-string|object|callable> $list
     * @return list<callable(Request, Closure(Request):Response):Response>
     */
    private function normalizeGlobalMiddleware(array $list): array
    {
        $out = [];

        foreach ($list as $mw) {
            // 1) Callable (closure, invokable object, [obj,'method'], etc.)
            if (\is_callable($mw) && !\is_string($mw)) {
                $out[] = $mw(...);
                continue;
            }

            // 2) Class-string → instantiate once per worker; must be invokable
            if (\is_string($mw)) {
                if (!\class_exists($mw)) {
                    throw new InvalidArgumentException("Middleware class '{$mw}' not found.");
                }
                $out[] = static function (Request $req, Closure $next) use ($mw): Response {
                    static $instance = null;     // one instance per worker
                    $instance ??= new $mw();     // direct instantiation to avoid DI invoking __invoke
                    if (!\is_callable($instance)) {
                        throw new InvalidArgumentException("Middleware {$mw} must be invokable (__invoke).");
                    }
                    return $instance($req, $next);
                };
                continue;
            }

            // 3) Object that isn’t callable → error (pass a callable or class-string)
            throw new InvalidArgumentException(
                sprintf('Unsupported middleware entry of type %s', gettype($mw))
            );
        }

        return $out;
    }

    /**
     * Compose a middleware pipeline into a single callable(Request): Response.
     *
     * @param list<callable(Request, Closure(Request):Response):Response> $stack
     * @param Closure(Request):Response $last
     * @return Closure(Request):Response
     */
    private function composePipeline(array $stack, Closure $last): Closure
    {
        $next = $last;
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $mw = $stack[$i];
            $next = static function (Request $req) use ($mw, $next): Response {
                return $mw($req, $next);
            };
        }
        return $next;
    }
}
