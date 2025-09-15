<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Psr\Log\LoggerInterface;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Route\{Collection, CompiledRoute, Route};
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};

final class RouterKernel
{
    /** Canonical alias filename (plural, double-underscore). */
    private const F_ALIASES = '__aliases.php';

    private ErrorHandler $errorHandler;
    private Invoker $invoker;
    private Dispatcher $dispatcher;

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
        // Globals are now resolved/applied inside Dispatcher
        $this->dispatcher = new Dispatcher(
            invoker: $this->invoker,
            useInvoker: $invokerOnMiddleware,
            preGlobal: $preGlobal,
            postGlobal: $postGlobal,
        );

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

        $runner = function (Request $req): Response {
            [$route, $vars] = $this->matchRoute($req);
            return $this->dispatcher->dispatch($route, $req, $vars);
        };

        return $this->errorHandler->handle($request, $runner);
    }

    /** @return array{CompiledRoute, array} */
    private function matchRoute(Request $req): array
    {
        $method = \strtoupper($req->getMethod());
        $uri = $req->getUri();
        $host = self::normaliseHost($uri->getHost());
        $path = $uri->getPath() ?: '/';
        return $this->matcher->match($method, $host, $path);
    }

    /* ───────── warm-up / cache prime ───────── */

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

        $aliasOnly = new Collection();
        $added = 0;
        if ($this->routeCache !== null) {
            $added = $this->hydrateAliasesFromCache($aliasOnly, $this->routeCache);
        }
        if ($added === 0 && $this->fallbackAliasesFromRegistrar) {
            $this->log->info('[router] alias cache empty; building aliases via registrar (matcher untouched)');
            $aliasOnly = $this->buildAliasesViaRegistrar(); // ← restored
            $added = \count($aliasOnly->aliasIndex());
        }

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
     * and alias cache isn’t available. (Matcher is NOT touched.)
     */
    private function buildAliasesViaRegistrar(): Collection
    {
        $routes = new Collection();

        $opts = $this->registrarOptions + [
                'autoSlashRedirect' => false,
                'exposeUrlServices' => false, // bind explicitly later
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

        // Let user add routes – matcher is NOT touched in this path.
        Router::setInstance($registrar);
        ($this->register)($registrar);

        // We only need the name/path map for URL helpers.
        return $routes;
    }

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

        $added = 0;
        foreach ($pairs as $name => $tuple) {
            if (!\is_string($name) || $name === '' || !\is_array($tuple)) continue;
            $path = $tuple[0] ?? null;
            $domain = $tuple[1] ?? null;
            if (!\is_string($path) || $path === '') continue;

            $r = new Route('GET', $path, static fn () => Response::noContent());
            $r = $r->withName($name);
            if (\is_string($domain) && $domain !== '') {
                $r = $r->withDomain($domain);
            }
            try { $dst->add($r); $added++; } catch (\Throwable) { /* skip dupes */ }
        }

        $this->log->info('[router] alias cache hydrated', ['file' => $aliasFile, 'count' => $added]);
        return $added;
    }

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
}
