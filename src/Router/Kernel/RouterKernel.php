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
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Route\{Collection, CompiledRoute, Route};
use Psr\Log\LoggerInterface;

/**
 * RouterKernel
 *
 * HTTP router kernel responsible for preparing routing state (warm-up/cache),
 * matching incoming requests to compiled routes and dispatching them via the
 * middleware dispatcher while providing a top-level error boundary.
 *
 * Responsibilities:
 *  - Warm and optionally hydrate route alias cache or build routes via a
 *    provided registrar callback on cold starts.
 *  - Compose Dispatcher with global middleware and Invoker settings.
 *  - Match HTTP requests using a MatcherInterface and hand CompiledRoute to
 *    the Dispatcher for execution.
 *  - Provide utilities to extract alias files and convert host headers to a
 *    normalized ASCII form.
 *
 * Instances are constructed with telemetry (PSR-3 logger), a matcher and a
 * registration callback and may be created via the static bootWithRegistrar
 * helper which will enable matcher caching when supported.
 *
 * @package Infocyph\Webrick\Router\Kernel
 */
final class RouterKernel
{
    /**
     * Canonical alias filename stored alongside route cache. Kept private and
     * constant to allow consistent file lookup across cache modes.
     */
    private const F_ALIASES = '__aliases.php';

    /**
     * Optional callback used to bind URL services (Response helpers) after warm-up.
     *
     * Signature: function(Collection $routes): void
     *
     * @var Closure|null
     */
    private ?Closure $bindUrlServices;

    /**
     * Dispatcher responsible for composing and executing middleware pipelines.
     *
     * @var Dispatcher
     */
    private Dispatcher $dispatcher;

    /**
     * Error boundary used for request handling.
     *
     * @var ErrorHandler
     */
    private ErrorHandler $errorHandler;

    /**
     * When true and an alias cache yields zero entries, the kernel will run the
     * registrar solely to build aliases while leaving the matcher (route table)
     * untouched. Default true.
     *
     * @var bool
     */
    private bool $fallbackAliasesFromRegistrar = true;

    /**
     * Shared DI invoker used for handler/middleware invocation.
     *
     * @var Invoker
     */
    private Invoker $invoker;

    /**
     * Matcher implementation used to add compiled routes and perform request matching.
     *
     * @var MatcherInterface
     */
    private MatcherInterface $matcher;

    /**
     * User-provided route registration callback executed on cold-path warm-up.
     *
     * Signature: function(Registrar $r): void
     *
     * @var Closure
     */
    private Closure $register;

    /**
     * Options forwarded to Registrar when building routes via the registrar path.
     *
     * @var array<string,mixed>
     */
    private array $registrarOptions;

    /**
     * Path to route cache. Can be a directory (sharded mode) or file (fused mode).
     * When null route caching is disabled.
     *
     * @var string|null
     */
    private ?string $routeCache;

    /**
     * Construct the RouterKernel.
     *
     * Note: The first parameter $log is promoted as a readonly property.
     *
     * @param LoggerInterface $log PSR-3 logger used for informational and error logging
     * @param MatcherInterface $matcher Matcher implementation used to add/lookup compiled routes
     * @param Closure $register User callback that registers routes onto a Registrar
     * @param string|null $routeCache Optional route cache path (directory or file); empty string treated as null
     * @param array<string,mixed> $registrarOptions Options forwarded to Registrar when invoked
     * @param array<int,mixed> $preGlobal Global "pre" middleware descriptors passed to Dispatcher
     * @param array<int,mixed> $postGlobal Global "post" middleware descriptors passed to Dispatcher
     * @param bool $invokerOnMiddleware If true, Dispatcher will use the Invoker when calling middleware
     * @param ErrorHandler|null $errorHandler Optional custom error handler (defaults created when null)
     * @param Closure|null $bindUrlServices Optional callback to bind URL services after warm-up
     * @param bool|null $fallbackAliasesFromRegistrar Optional override for alias fallback behaviour
     */
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

    /**
     * Bootstrap helper that optionally enables matcher caching and returns a
     * configured RouterKernel instance.
     *
     * This factory will call Matcher::enableCache when the matcher supports it
     * and a non-empty cache location is supplied.
     *
     * @param LoggerInterface $log
     * @param MatcherInterface $matcher
     * @param Closure $register Registrar callback
     * @param string|null $routeCache Cache location (directory or file) or null
     * @param array<string,mixed> $registrarOptions Options forwarded to Registrar
     * @param array<int,mixed> $preGlobal Global "pre" middleware descriptors
     * @param array<int,mixed> $postGlobal Global "post" middleware descriptors
     * @param bool $invokerOnMiddleware Whether to use Invoker for middleware invocation
     * @param ErrorHandler|null $errorHandler Optional error handler override
     * @param Closure|null $bindUrlServices Optional callback to bind URL services
     * @param bool|null $fallbackAliasesFromRegistrar Optional alias fallback behaviour override
     * @return self Configured RouterKernel instance
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

    /**
     * Handle an incoming HTTP request through the router kernel.
     *
     * The Request is bound into the DI container for consumption by handlers.
     * The actual route matching and dispatch is executed inside the error
     * boundary provided by the ErrorHandler.
     *
     * @param Request|null $request Optional request to handle; when null Request::fromGlobals() is used
     * @return Response Response produced by the dispatched handler or by the error renderer
     */
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

    /**
     * Normalize a Host header value to its ASCII, lower-cased, non-trailing-dot form.
     *
     * Behaviour and validations:
     *  - Empty host or host containing control characters will throw InvalidArgumentException.
     *  - Trailing dots are removed.
     *  - If idn_to_ascii is available, internationalized names will be converted to ASCII.
     *  - Hosts containing non-printable/extended bytes after conversion will throw.
     *
     * @param string $raw Raw Host header value
     * @return string Normalized ASCII host name
     * @throws \InvalidArgumentException When the Host is illegal, invalid IDN, or contains non-ASCII bytes
     */
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
     * Check whether the alias cache file exists and is a regular file.
     *
     * @param string|null $path Path to check (may be null)
     * @return bool True when path is a string and points to an existing file
     */
    private function aliasFileExists(?string $path): bool
    {
        return \is_string($path) && \is_file($path);
    }

    /**
     * Compute the canonical alias file path for the provided cache location.
     *
     * If $cacheLocation is a directory the alias file is created inside it,
     * otherwise the alias file is placed in the same directory as the given file.
     *
     * @param string $cacheLocation Directory or file path used for route cache
     * @return string|null Resolved alias file path or null when $cacheLocation is null/empty
     */
    private function aliasFilePath(string $cacheLocation): ?string
    {
        return (
            \is_dir($cacheLocation)
                ? \rtrim($cacheLocation, '/\\')
                : \dirname($cacheLocation)
        ) . \DIRECTORY_SEPARATOR . self::F_ALIASES;
    }

    /**
     * Registrar-only pass to build a Collection containing named route aliases
     * (used when alias cache is hot but empty and we need name -> path mapping).
     *
     * The matcher is intentionally NOT altered by this pass.
     *
     * @return Collection Collection populated with named routes (alias-only)
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

    /**
     * Hydrate a destination Collection with named route aliases read from the
     * alias cache file. Returns the number of successfully added alias entries.
     *
     * Expected cache blob format:
     *   ['_data' => [ name => [path, domain|null], ... ]]
     *
     * @param Collection $dst Destination collection to populate with alias routes
     * @param string $cacheLocation Path to cache (directory or file)
     * @return int Number of alias entries added to $dst
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
            } catch (\Throwable) { /* skip dupes */
            }
        }

        $this->log->info('[router] alias cache hydrated', ['file' => $aliasFile, 'count' => $added]);
        return $added;
    }

    /**
     * Validate the alias blob structure returned from requireAliasBlob().
     *
     * Expected shape: array with key '_data' whose value is an array.
     *
     * @param mixed $blob Value returned from included alias file
     * @return bool True when $blob is an array and contains an array under '_data'
     */
    private function isValidAliasBlob(mixed $blob): bool
    {
        return \is_array($blob) && isset($blob['_data']) && \is_array($blob['_data']);
    }

    /**
     * Match the incoming request to a compiled route using the matcher.
     *
     * Returns a two-element tuple: [CompiledRoute, array<string,mixed> vars].
     *
     * @param Request $req
     * @return array{0:CompiledRoute,1:array<string,mixed>} Tuple of matched compiled route and extracted variables
     */
    private function matchRoute(Request $req): array
    {
        // Respect method overrides for routing while preserving explicit HEAD routes.
        $rawMethod = \strtoupper($req->getMethod());
        $effectiveMethod = \strtoupper($req->getEffectiveMethod());
        $method = ($rawMethod === 'HEAD') ? 'HEAD' : $effectiveMethod;
        $uri = $req->getUri();
        $host = self::normaliseHost($uri->getHost());
        $path = $uri->getPath() ?: '/';
        return $this->matcher->match($method, $host, $path);
    }

    /**
     * Require and return the contents of the alias file.
     *
     * This wraps the include with a Psalm suppression to allow dynamic includes
     * whose return type cannot be resolved statically.
     *
     * @param string $file Path to alias PHP file that returns the alias blob
     * @return mixed The value returned by the required file (expected array blob)
     *
     * @psalm-suppress UnresolvableInclude
     */
    private function requireAliasBlob(string $file)
    {
        /** @psalm-suppress UnresolvableInclude */
        return require $file;
    }

    /* -----------------------------------------------------------------
     * Warm-up / cache priming helpers
     * ----------------------------------------------------------------- */

    /**
     * Warm the router state either from cache or by invoking the registrar.
     *
     * Chooses cache path when the matcher indicates cache-boot support.
     *
     * @return void
     */
    private function warm(): void
    {
        if ($this->matcher->canBootFromCache()) {
            $this->warmFromCache();
            return;
        }
        $this->warmFromRegistrar();
    }

    /**
     * Warm from matcher cache: finalize matcher, hydrate alias-only collection
     * if available, or optionally run registrar to build aliases when cache is
     * empty. Binds URL helpers after alias hydration.
     *
     * @return void
     */
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
            $signKey = $this->registrarOptions['signKey'] ?? null;
            if (!\is_string($signKey) || $signKey === '') {
                $signKey = null;
            }

            $ttlRaw = $this->registrarOptions['signedDefaultTtl'] ?? null;
            $defaultTtl = \is_int($ttlRaw)
                ? $ttlRaw
                : (\is_string($ttlRaw) && $ttlRaw !== '' ? (int)$ttlRaw : null);

            Response::bindUrlServices($aliasOnly, $signKey, $defaultTtl);
        }

        $this->log->info('[router] route table ready (hot cache)', [
            'matcher' => $this->matcher::class,
            'cache' => true,
            'mode' => 'cache',
            'aliases' => $added,
        ]);
    }

    /**
     * Warm by invoking the registrar callback to compile and add routes to the matcher.
     *
     * This method builds a temporary Collection, constructs a Registrar using
     * configured options, executes the user registration callback, compiles the
     * routes and populates the matcher. It also binds URL services when requested.
     *
     * @return void
     * @throws \RuntimeException When registration produces an empty compiled route table
     */
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
}
