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
 * Rings:
 *   1) Pre-route (global)
 *   2) Per-route (Dispatcher)
 *   3) Post-controller (global)
 *
 * Errors are handled natively by ErrorHandler (always-on).
 */
final class RouterKernel
{
    /** @var Closure():list<CompiledRoute> */
    private Closure $compiler;

    private ErrorHandler $errorHandler;
    private Invoker $invoker;
    private Dispatcher $dispatcher;
    /**
     * @var callable[]
     */
    private array $preGlobal;
    /**
     * @var callable[]
     */
    private array $postGlobal;

    public function __construct(
        private readonly LoggerInterface $log,
        Closure $compiler,
        private readonly MatcherInterface $matcher,
        array $preGlobal = [],
        array $postGlobal = [],
        bool $invokerOnMiddleware = false,
        ?ErrorHandler $errorHandler = null,
    ) {
        $this->compiler = $compiler;
        $this->warm();
        $this->invoker = Invoker::shared();
        $this->dispatcher = new Dispatcher($this->invoker, $invokerOnMiddleware);

        // Accept objects, class-strings, or callables
        $this->preGlobal = $this->normalizeGlobalMiddleware($preGlobal);
        $this->postGlobal = $this->normalizeGlobalMiddleware($postGlobal);

        // Hard-bind native error handler (always present)
        $this->errorHandler = $errorHandler ?? new ErrorHandler(
            logger: $this->log,
            debug: true,
            capturePhpErrors: true,
            requestIdHeader: 'X-Request-Id',
            exceptionMap: [
                RouteNotFoundException::class => 404,
                MethodNotAllowedException::class => 405,
            ],
        );
    }

    /*──────────────── bootstrap helper ────────────────*/

    /**
     * @param string|null $routeCache Dir for UnifiedMatcher **or** file for MergedMatcher
     * @param bool $invokerOnMiddleware Call per-route stack via DI Invoker (true) or manually (false)
     */
    public static function boot(
        LoggerInterface $log,
        Closure $compiler,
        MatcherInterface $matcher,
        ?string $routeCache = null,
        array $preGlobal = [],
        array $postGlobal = [],
        bool $invokerOnMiddleware = false,
        ?ErrorHandler $errorHandler = null,
    ): self {
        if ($routeCache) {
            $matcher->enableCache($routeCache);
        }

        return new self(
            $log,
            $compiler,
            $matcher,
            $preGlobal,
            $postGlobal,
            $invokerOnMiddleware,
            $errorHandler,
        );
    }

    /*──────────────── request entry ───────────────────*/

    public function handle(Request $request): Response
    {
        // Bind Request for DI across pre/per-route/post rings
        $this->invoker->getContainer()->definitions()
            ->bind(Request::class, $request);

        // Core: match → dispatch (let exceptions bubble to ErrorHandler)
        $dispatchCore = $this->buildDispatchCore();

        // Wrap core with post-global middleware
        $withPost = $this->composePipeline($this->postGlobal, $dispatchCore);

        // Wrap with pre-global middleware
        $withPre = $this->composePipeline($this->preGlobal, $withPost);

        // Native error boundary around the whole pipeline
        return $this->errorHandler->handle($request, $withPre);
    }

    private function buildDispatchCore(): Closure
    {
        return function (Request $req): Response {
            [$route, $vars] = $this->matchRoute($req);
            return $this->dispatcher->dispatch($route, $req, $vars);
        };
    }

    /**
     * @return array{CompiledRoute, array} [route, vars]
     */
    private function matchRoute(Request $req): array
    {
        $method = strtoupper($req->getMethod());
        $uri = $req->getUri();
        $host = self::normaliseHost($uri->getHost());
        $path = $uri->getPath() ?: '/';

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
                sprintf('Unsupported middleware entry of type %s', gettype($mw)),
            );
        }

        return $out;
    }

    /**
     * Compose a middleware pipeline into a single callable(Request): Response.
     *
     * @param list<callable(Request, Closure(Request):Response):Response> $stack
     * @param Closure $next
     * @return Closure(Request):Response
     */
    private function composePipeline(array $stack, Closure $next): Closure
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $mw = $stack[$i];
            $next = static function (Request $req) use ($mw, $next): Response {
                return $mw($req, $next);
            };
        }
        return $next;
    }
}
