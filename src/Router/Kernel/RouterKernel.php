<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Route\{Collection, CompiledRoute, Route};
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Option B kernel:
 *  - Hot cache: do not register routes; hydrate alias-only Collection for URL helpers.
 *  - Cold path: run registrar → compile → add to matcher.
 *  - If alias cache is missing/empty, optionally run registrar ONLY to build alias Collection (matcher untouched).
 */
final class RouterKernel
{
    /** Canonical non-filename alias key (reserved for consistency across codebase). */
    private const K_ALIAS = '_alias';

    /** Canonical alias filename (plural, double-underscore). */
    private const F_ALIASES = '__aliases.php';

    private ErrorHandler $errorHandler;
    private Invoker $invoker;
    private Dispatcher $dispatcher;

    /** @var list<callable(Request,Closure(Request):Response):Response> */
    private array $preGlobal;

    /** @var list<callable(Request,Closure(Request):Response):Response> */
    private array $postGlobal;

    /** Cache location (dir for Sharded, file for Fused); null disables cache. */
    private ?string $routeCache;

    /** Optional callback to bind Response URL services after warm-up. */
    private ?Closure $bindUrlServices;

    /** Your route registration closure (used on cold path; on hot path only for alias fallback). */
    private Closure $register;

    /** Options for Registrar constructor. */
    private array $registrarOptions;

    private MatcherInterface $matcher;

    /** When true, if alias cache yields 0 entries, run registrar just to build aliases (matcher untouched). */
    private bool $fallbackAliasesFromRegistrar = true;

    public function __construct(
        private readonly LoggerInterface $log,
        MatcherInterface $matcher,
        Closure $register,
        ?string $routeCache = null,
        array $registrarOptions = [],
        array $preGlobal = [],
        array $postGlobal = [],
        bool $invokerOnMiddleware = false,
        ?ErrorHandler $errorHandler = null,
        ?Closure $bindUrlServices = null,
        ?bool $fallbackAliasesFromRegistrar = null,
    ) {
        $this->routeCache = ($routeCache !== '' ? $routeCache : null);
        $this->bindUrlServices = $bindUrlServices;
        $this->register = $register;
        $this->registrarOptions = $registrarOptions;
        $this->matcher = $matcher;
        if ($fallbackAliasesFromRegistrar !== null) {
            $this->fallbackAliasesFromRegistrar = $fallbackAliasesFromRegistrar;
        }

        $this->warm();

        $this->invoker = Invoker::shared();
        $this->dispatcher = new Dispatcher($this->invoker, $invokerOnMiddleware);

        // ⚠️ Global middleware: now supports alias strings like 'throttle:60,60'
        $this->preGlobal = $this->normalizeGlobalMiddleware($preGlobal);
        $this->postGlobal = $this->normalizeGlobalMiddleware($postGlobal);

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

    /**
     * Boot with a registrar closure (Option B).
     */
    public static function bootWithRegistrar(
        LoggerInterface $log,
        MatcherInterface $matcher,
        Closure $register,
        ?string $routeCache = null,
        array $registrarOptions = [],
        array $preGlobal = [],
        array $postGlobal = [],
        bool $invokerOnMiddleware = false,
        ?ErrorHandler $errorHandler = null,
        ?Closure $bindUrlServices = null,
        ?bool $fallbackAliasesFromRegistrar = null,
    ): self {
        $normalizedCache = ($routeCache !== null && $routeCache !== '') ? $routeCache : null;
        if ($normalizedCache !== null && \method_exists($matcher, 'enableCache')) {
            $matcher->enableCache($normalizedCache);
        }

        return new self(
            log: $log,
            matcher: $matcher,
            register: $register,
            routeCache: $normalizedCache,
            registrarOptions: $registrarOptions,
            preGlobal: $preGlobal,
            postGlobal: $postGlobal,
            invokerOnMiddleware: $invokerOnMiddleware,
            errorHandler: $errorHandler,
            bindUrlServices: $bindUrlServices,
            fallbackAliasesFromRegistrar: $fallbackAliasesFromRegistrar,
        );
    }

    public function handle(?Request $request = null): Response
    {
        $request ??= Request::fromGlobals();
        $this->invoker->getContainer()->definitions()->bind(Request::class, $request);

        $dispatchCore = $this->buildDispatchCore();
        $withPost = $this->composePipeline($this->postGlobal, $dispatchCore);
        $withPre = $this->composePipeline($this->preGlobal, $withPost);

        return $this->errorHandler->handle($request, $withPre);
    }

    private function buildDispatchCore(): Closure
    {
        return function (Request $req): Response {
            [$route, $vars] = $this->matchRoute($req);
            return $this->dispatcher->dispatch($route, $req, $vars);
        };
    }

    /** @return array{CompiledRoute, array} */
    private function matchRoute(Request $req): array
    {
        $method = strtoupper($req->getMethod());
        $uri = $req->getUri();
        $host = self::normaliseHost($uri->getHost());
        $path = $uri->getPath() ?: '/';

        return $this->matcher->match($method, $host, $path);
    }

    /* ────────────────── warm-up / cache prime ────────────────── */

    private function warm(): void
    {
        if ($this->matcher->canBootFromCache()) {
            $this->warmFromCache();
            return;
        }
        $this->warmFromRegistrar();
    }

    private function warmFromCache(): void
    {
        $this->matcher->finalize();

        // Build alias-only collection (for URL helpers)
        $aliasOnly = new Collection();
        $added = 0;
        if ($this->routeCache !== null) {
            $added = $this->hydrateAliasesFromCache($aliasOnly, $this->routeCache);
        }

        // Fallback: registrar-only aliases (matcher untouched)
        if ($added === 0 && $this->fallbackAliasesFromRegistrar) {
            $this->log->info('[router] alias cache empty; building aliases via registrar (matcher untouched)');
            $aliasOnly = $this->buildAliasesViaRegistrar();
            $added = \count($aliasOnly->aliasIndex());
        }

        // Always bind URL services; prefer user callback
        if ($this->bindUrlServices) {
            ($this->bindUrlServices)($aliasOnly);
        } else {
            Response::bindUrlServices($aliasOnly, null, null);
        }

        $this->log->info('[router] route table ready (hot cache)', [
            'matcher' => $this->matcher::class,
            'cache' => true,
            'mode' => 'cache',
            'aliases' => $added,
        ]);
    }

    private function warmFromRegistrar(): void
    {
        $routes = new Collection();

        $opts = $this->registrarOptions + [
                'autoSlashRedirect' => false,
                'exposeUrlServices' => false,
                'signKey' => null,
                'signedDefaultTtl' => null,
            ];

        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: (bool)$opts['autoSlashRedirect'],
            exposeUrlServices: (bool)$opts['exposeUrlServices'],
            signKey: $opts['signKey'],
            signedDefaultTtl: $opts['signedDefaultTtl'],
        );
        Router::setInstance($registrar);
        ($this->register)($registrar);

        $compiled = $routes->compile()->all();
        if ($compiled === []) {
            throw new \RuntimeException('Registration produced an empty route table.');
        }
        foreach ($compiled as $r) {
            $this->matcher->add($r);
        }
        $this->matcher->finalize();

        if ($this->bindUrlServices) {
            ($this->bindUrlServices)($routes);
        }

        $this->log->info('[router] route table ready', [
            'count' => \count($compiled),
            'matcher' => $this->matcher::class,
            'cache' => \method_exists($this->matcher, 'enableCache'),
            'mode' => 'compiled',
        ]);
    }

    /**
     * Registrar-only pass to build an alias Collection when cache is hot
     * and alias cache isn’t available.
     */
    private function buildAliasesViaRegistrar(): Collection
    {
        $routes = new Collection();

        $opts = $this->registrarOptions + [
                'autoSlashRedirect' => false,
                'exposeUrlServices' => false, // we’ll bind explicitly below
                'signKey' => null,
                'signedDefaultTtl' => null,
            ];

        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: (bool)$opts['autoSlashRedirect'],
            exposeUrlServices: false,
            signKey: $opts['signKey'],
            signedDefaultTtl: $opts['signedDefaultTtl'],
        );

        Router::setInstance($registrar);
        ($this->register)($registrar);

        return $routes;
    }

    /**
     * Hydrate minimal Collection from the canonical alias cache file.
     *
     * Returns number of aliases added.
     */
    private function hydrateAliasesFromCache(Collection $dst, string $cacheLocation): int
    {
        $aliasFile = $this->aliasFilePath($cacheLocation);
        if (!$this->aliasFileExists($aliasFile)) {
            $this->log->warning('[router] alias cache file not found; URL helpers may be limited', [
                'cache' => $cacheLocation,
            ]);
            return 0;
        }

        $blob = $this->requireAliasBlob($aliasFile);
        if (!$this->isValidAliasBlob($blob)) {
            $this->log->warning('[router] alias cache has unexpected format; URL helpers may be limited', [
                'file' => $aliasFile,
            ]);
            return 0;
        }

        /** @var array<string, array{0:string,1:?string}> $pairs */
        $pairs = $blob['_data'];

        $added = $this->addAliasPairsToCollection($dst, $pairs);

        $this->log->info('[router] alias cache hydrated', [
            'file' => $aliasFile,
            'count' => $added,
        ]);

        return $added;
    }

    /** Resolve the canonical alias file path for either cache dir (sharded) or file (fused). */
    private function aliasFilePath(string $cacheLocation): ?string
    {
        return (
            \is_dir($cacheLocation)
                ? \rtrim($cacheLocation, '/\\')
                : \dirname($cacheLocation)
        ) . \DIRECTORY_SEPARATOR . self::F_ALIASES;
    }

    private function aliasFileExists(?string $path): bool
    {
        return \is_string($path) && \is_file($path);
    }

    /** @return mixed */
    private function requireAliasBlob(string $file)
    {
        /** @psalm-suppress UnresolvableInclude */
        return require $file;
    }

    /** @param mixed $blob */
    private function isValidAliasBlob(mixed $blob): bool
    {
        return \is_array($blob) && isset($blob['_data']) && \is_array($blob['_data']);
    }

    /** @param array<string, array{0:string,1:?string}> $pairs */
    private function addAliasPairsToCollection(Collection $dst, array $pairs): int
    {
        $added = 0;
        foreach ($pairs as $name => $tuple) {
            if (!\is_string($name) || $name === '' || !\is_array($tuple)) {
                continue;
            }
            $path = $tuple[0] ?? null;
            $domain = $tuple[1] ?? null;
            if (!\is_string($path) || $path === '') {
                continue;
            }

            $r = new Route('GET', $path, static fn () => Response::noContent());
            $r = $r->withName($name);
            if (\is_string($domain) && $domain !== '') {
                $r = $r->withDomain($domain);
            }
            try {
                $dst->add($r);
                $added++;
            } catch (\Throwable) {
                /* skip dupes or invalids */
            }
        }
        return $added;
    }

    /* ─────────────── helpers ─────────────── */

    private static function normaliseHost(string $raw): string
    {
        if ($raw === '' || \preg_match('/[\x00-\x20]/', $raw)) {
            throw new \InvalidArgumentException('Illegal Host header.');
        }
        $host = \strtolower(\rtrim($raw, '.'));

        if (\function_exists('idn_to_ascii') && !\str_contains($host, 'xn--')) {
            $ascii = @\idn_to_ascii($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException('Invalid IDN host name.');
            }
            $host = $ascii;
        }
        if (!\preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException('Host contains non-ASCII bytes.');
        }
        return $host;
    }

    /**
     * Normalize global middleware entries to invokables.
     * Supports:
     *  • callable/closure
     *  • object implementing __invoke
     *  • class-string (lazy single instance)
     *  • alias-string like "throttle:60,60" (resolved via MiddlewareAliases)
     *
     * @param array<class-string|object|callable|string> $list
     * @return list<callable(Request, Closure(Request):Response):Response>
     */
    private function normalizeGlobalMiddleware(array $list): array
    {
        $out = [];

        foreach ($list as $mw) {
            // Direct callable or invokable object
            if (\is_callable($mw) && !\is_string($mw)) {
                $out[] = $mw(...);
                continue;
            }

            if (\is_string($mw)) {
                // Alias-string?
                if ($this->looksLikeAliasString($mw)) {
                    $out[] = $this->wrapAliasStringAsMiddleware($mw);
                    continue;
                }

                // Class-string
                if (!\class_exists($mw)) {
                    throw new InvalidArgumentException("Middleware class or alias '{$mw}' not found.");
                }
                $out[] = static function (Request $req, Closure $next) use ($mw): Response {
                    static $instance = null;
                    $instance ??= new $mw();
                    if (!\is_callable($instance)) {
                        throw new InvalidArgumentException("Middleware {$mw} must be invokable (__invoke).");
                    }
                    return $instance($req, $next);
                };
                continue;
            }

            throw new InvalidArgumentException(
                \sprintf('Unsupported middleware entry of type %s', \gettype($mw)),
            );
        }

        return $out;
    }

    private function looksLikeAliasString(string $s): bool
    {
        if (\class_exists($s)) {
            return false; // it's a class-string, not an alias
        }
        $name = \strtolower(\trim(\explode(':', $s, 2)[0] ?? ''));
        return $name !== '' && MiddlewareAliases::has($name);
    }

    /**
     * Turn an alias string (e.g. "throttle:60,60") into a global-middleware wrapper.
     * We resolve once lazily, memoizing the instance.
     */
    private function wrapAliasStringAsMiddleware(string $alias): callable
    {
        return static function (Request $req, Closure $next) use ($alias): Response {
            static $resolved = null; // one instance per-process
            $resolved ??= MiddlewareAliases::resolveString($alias);
            if (\is_string($resolved)) {
                // If resolver returned a class-string, instantiate lazily (single)
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
            // Should not happen – guard anyway
            throw new InvalidArgumentException("Failed to resolve middleware alias '{$alias}'.");
        };
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
        for ($i = \count($stack) - 1; $i >= 0; $i--) {
            $mw = $stack[$i];
            $next = static function (Request $req) use ($mw, $next): Response {
                return $mw($req, $next);
            };
        }
        return $next;
    }
}
