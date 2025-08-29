<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\{CompiledRoute, Route, Collection};
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};

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

    /** No-op compiler kept for API parity. */
    private Closure $compiler;

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

        $this->compiler = static fn (): array => [];

        $this->warm();

        $this->invoker = Invoker::shared();
        $this->dispatcher = new Dispatcher($this->invoker, $invokerOnMiddleware);

        $this->preGlobal  = $this->normalizeGlobalMiddleware($preGlobal);
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

    public function handle(Request $request): Response
    {
        $this->invoker->getContainer()->definitions()->bind(Request::class, $request);

        $dispatchCore = $this->buildDispatchCore();
        $withPost = $this->composePipeline($this->postGlobal, $dispatchCore);
        $withPre  = $this->composePipeline($this->preGlobal,  $withPost);

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
        $uri    = $req->getUri();
        $host   = self::normaliseHost($uri->getHost());
        $path   = $uri->getPath() ?: '/';

        return $this->matcher->match($method, $host, $path);
    }

    /* ────────────────── warm-up / cache prime ────────────────── */

    private function warm(): void
    {
        if ($this->matcher->canBootFromCache()) {
            $this->matcher->finalize();

            // Build alias-only collection
            $aliasOnly = new Collection();
            $added = 0;
            if ($this->routeCache !== null) {
                $added = $this->hydrateAliasesFromCache($aliasOnly, $this->routeCache);
            }

            // Fallback: registrar-only aliases (matcher untouched)
            if ($added === 0 && $this->fallbackAliasesFromRegistrar) {
                $this->log->info('[router] alias cache empty; building aliases via registrar (matcher untouched)');
                $aliasOnly = $this->buildAliasesViaRegistrar();
                $added = count($aliasOnly->aliasIndex());
            }

            // Always bind URL services; prefer user callback
            if ($this->bindUrlServices) {
                ($this->bindUrlServices)($aliasOnly);
            } else {
                Response::bindUrlServices($aliasOnly, null, null);
            }

            $this->log->info('[router] route table ready (hot cache)', [
                'matcher' => $this->matcher::class,
                'cache'   => true,
                'mode'    => 'cache',
                'aliases' => $added,
            ]);
            return;
        }

        // Cold path: run registrar → compile → add to matcher.
        $routes = new Collection();

        $opts = $this->registrarOptions + [
                'autoSlashRedirect' => false,
                'exposeUrlServices' => false,
                'signKey'           => null,
                'signedDefaultTtl'  => null,
            ];

        $registrar = new \Infocyph\Webrick\Router\Definition\Registrar(
            routes: $routes,
            autoSlashRedirect: (bool)$opts['autoSlashRedirect'],
            exposeUrlServices: (bool)$opts['exposeUrlServices'],
            signKey: $opts['signKey'],
            signedDefaultTtl: $opts['signedDefaultTtl'],
        );

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
            'count'   => count($compiled),
            'matcher' => $this->matcher::class,
            'cache'   => \method_exists($this->matcher, 'enableCache'),
            'mode'    => 'compiled',
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
                'signKey'           => null,
                'signedDefaultTtl'  => null,
            ];

        $registrar = new \Infocyph\Webrick\Router\Definition\Registrar(
            routes: $routes,
            autoSlashRedirect: (bool)$opts['autoSlashRedirect'],
            exposeUrlServices: false,
            signKey: $opts['signKey'],
            signedDefaultTtl: $opts['signedDefaultTtl'],
        );

        // Let user add routes – matcher is NOT touched in this path.
        ($this->register)($registrar);

        // We don’t compile or add to matcher; we only need the name/path map.
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
        if ($aliasFile === null || !\is_file($aliasFile)) {
            $this->log->warning('[router] alias cache file not found; URL helpers may be limited', [
                'cache' => $cacheLocation,
            ]);
            return 0;
        }

        /** @var mixed $blob */
        $blob = require $aliasFile;

        // Expect the standard structure from matchers:
        // return ['_hash'=>..., '_ts'=>..., '_data'=> array<string, [string, ?string]> ]
        if (!\is_array($blob) || !isset($blob['_data']) || !\is_array($blob['_data'])) {
            $this->log->warning('[router] alias cache has unexpected format; URL helpers may be limited', [
                'file' => $aliasFile,
            ]);
            return 0;
        }

        /** @var array<string, array{0:string,1:?string}> $pairs */
        $pairs = $blob['_data'];

        $added = 0;
        foreach ($pairs as $name => $tuple) {
            if (!\is_string($name) || $name === '' || !\is_array($tuple)) {
                continue;
            }
            $path   = $tuple[0] ?? null;
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

        $this->log->info('[router] alias cache hydrated', [
            'file'  => $aliasFile,
            'count' => $added,
        ]);

        return $added;
    }

    /** Resolve the canonical alias file path for either cache dir (sharded) or file (fused). */
    private function aliasFilePath(string $cacheLocation): ?string
    {
        $dir = \is_dir($cacheLocation) ? \rtrim($cacheLocation, '/\\') : \dirname($cacheLocation);
        return $dir . DIRECTORY_SEPARATOR . self::F_ALIASES;
    }

    /* ─────────────── helpers ─────────────── */

    private static function normaliseHost(string $raw): string
    {
        if ($raw === '' || preg_match('/[\x00-\x20]/', $raw)) {
            throw new \InvalidArgumentException('Illegal Host header.');
        }
        $host = rtrim(strtolower($raw), '.');

        if (function_exists('idn_to_ascii') && !str_contains($host, 'xn--')) {
            /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
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
     * Normalize global middleware entries to invokables.
     *
     * @param array<class-string|object|callable> $list
     * @return list<callable(Request, Closure(Request):Response):Response>
     */
    private function normalizeGlobalMiddleware(array $list): array
    {
        $out = [];

        foreach ($list as $mw) {
            if (\is_callable($mw) && !\is_string($mw)) {
                $out[] = $mw(...);
                continue;
            }

            if (\is_string($mw)) {
                if (!\class_exists($mw)) {
                    throw new InvalidArgumentException("Middleware class '{$mw}' not found.");
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
