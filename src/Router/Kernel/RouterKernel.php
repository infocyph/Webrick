<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\InterMix\DI\Support\DirectFactory;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Infocyph\Webrick\Router\Url\UrlGeneratorRegistry;
use LogicException;
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
 */
final class RouterKernel
{
    /**
     * Canonical alias filename stored alongside route cache. Kept private and
     * constant to allow consistent file lookup across cache modes.
     */
    private const string F_ALIASES = '__aliases.php';

    /**
     * Dispatcher responsible for composing and executing middleware pipelines.
     */
    private readonly Dispatcher $dispatcher;

    /**
     * Error boundary used for request handling.
     */
    private readonly ErrorHandler $errorHandler;

    /** DI invoker used for handler/middleware invocation. */
    private readonly Invoker $invoker;

    /**
     * Path to route cache. Can be a directory (sharded mode) or file (fused mode).
     * When null route caching is disabled.
     */
    private readonly ?string $routeCache;

    /**
     * When true and an alias cache yields zero entries, the kernel will run the
     * registrar solely to build aliases while leaving the matcher (route table)
     * untouched. Default true.
     */
    private bool $fallbackAliasesFromRegistrar = true;

    /** Monotonic counter for per-request scope names. */
    private int $requestScopeSeq = 0;

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
     * @param array<int, string|ServiceProviderInterface> $serviceProviders InterMix service providers to import at boot
     * @param array<int, string> $preGlobalTags Container tags auto-appended to preGlobal middleware
     * @param array<int, string> $postGlobalTags Container tags auto-appended to postGlobal middleware
     * @param bool $requestScopeEnabled Whether to wrap handle() in enterScope/leaveScope lifecycle
     * @param Container|null $container Optional container to use for kernel DI
     * @param Invoker|null $invoker Optional invoker to use for kernel DI
     * @param bool $debug Whether default error responses may expose diagnostic details
     * @param bool $capturePhpErrors Whether the default boundary converts PHP warnings and notices to exceptions
     */
    public function __construct(
        private readonly LoggerInterface $log,
        /**
         * Matcher implementation used to add compiled routes and perform request matching.
         */
        private readonly MatcherInterface $matcher,
        /**
         * User-provided route registration callback executed on cold-path warm-up.
         *
         * Signature: function(Registrar $r): void
         */
        private readonly Closure $register,
        ?string $routeCache = null,
        /**
         * Options forwarded to Registrar when building routes via the registrar path.
         */
        private array $registrarOptions = [],
        array $preGlobal = [],
        array $postGlobal = [],
        bool $invokerOnMiddleware = false,
        ?ErrorHandler $errorHandler = null,
        /**
         * Optional callback used to bind URL services (Response helpers) after warm-up.
         *
         * Signature: function(Collection $routes): void
         */
        private readonly ?Closure $bindUrlServices = null,
        ?bool $fallbackAliasesFromRegistrar = null,
        private readonly array $serviceProviders = [],
        private readonly array $preGlobalTags = ['webrick.middleware.pre'],
        private readonly array $postGlobalTags = ['webrick.middleware.post'],
        private readonly bool $requestScopeEnabled = true,
        ?Container $container = null,
        ?Invoker $invoker = null,
        private readonly bool $debug = true,
        private readonly bool $capturePhpErrors = true,
    ) {
        $this->routeCache = ($routeCache !== '' ? $routeCache : null);
        if ($fallbackAliasesFromRegistrar !== null) {
            $this->fallbackAliasesFromRegistrar = $fallbackAliasesFromRegistrar;
        }

        // Keep kernel DI and Response::view() on the same container alias.
        $this->invoker = $invoker ?? Invoker::with($container ?? Container::instance('intermix'));
        $this->importServiceProviders();

        $this->warm();
        [$preGlobal, $postGlobal] = $this->prepareGlobalMiddleware($preGlobal, $postGlobal);
        $this->dispatcher = $this->createDispatcher($invokerOnMiddleware, $preGlobal, $postGlobal);
        $this->errorHandler = $errorHandler ?? $this->createDefaultErrorHandler();
    }

    /**
     * Bootstrap helper that optionally enables matcher caching and returns a
     * configured RouterKernel instance.
     *
     * This factory will call Matcher::enableCache when the matcher supports it
     * and a non-empty cache location is supplied.
     *
     * @param Closure $register Registrar callback
     * @param string|null $routeCache Cache location (directory or file) or null
     * @param array<string,mixed> $registrarOptions Options forwarded to Registrar
     * @param array<int,mixed> $preGlobal Global "pre" middleware descriptors
     * @param array<int,mixed> $postGlobal Global "post" middleware descriptors
     * @param bool $invokerOnMiddleware Whether to use Invoker for middleware invocation
     * @param ErrorHandler|null $errorHandler Optional error handler override
     * @param Closure|null $bindUrlServices Optional callback to bind URL services
     * @param bool|null $fallbackAliasesFromRegistrar Optional alias fallback behaviour override
     * @param array<int, string|ServiceProviderInterface> $serviceProviders InterMix service providers to import at boot
     * @param array<int, string> $preGlobalTags Container tags auto-appended to preGlobal middleware
     * @param array<int, string> $postGlobalTags Container tags auto-appended to postGlobal middleware
     * @param bool $requestScopeEnabled Whether to wrap handle() in enterScope/leaveScope lifecycle
     * @param Container|null $container Optional container to use for kernel DI
     * @param Invoker|null $invoker Optional invoker to use for kernel DI
     * @param bool $debug Whether default error responses may expose diagnostic details
     * @param bool $capturePhpErrors Whether the default boundary converts PHP warnings and notices to exceptions
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
        array $serviceProviders = [],
        array $preGlobalTags = ['webrick.middleware.pre'],
        array $postGlobalTags = ['webrick.middleware.post'],
        bool $requestScopeEnabled = true,
        ?Container $container = null,
        ?Invoker $invoker = null,
        bool $debug = true,
        bool $capturePhpErrors = true,
    ): self {
        $normalizedCache = ($routeCache !== null && $routeCache !== '') ? $routeCache : null;
        if ($normalizedCache !== null) {
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
            serviceProviders: $serviceProviders,
            preGlobalTags: $preGlobalTags,
            postGlobalTags: $postGlobalTags,
            requestScopeEnabled: $requestScopeEnabled,
            container: $container,
            invoker: $invoker,
            debug: $debug,
            capturePhpErrors: $capturePhpErrors,
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
        $runner = function (Request $req): Response {
            [$route, $vars] = $this->matchRoute($req);

            return $this->dispatcher->dispatch($route, $req, $vars);
        };

        $container = $this->invoker->getContainer();

        if (!$this->requestScopeEnabled) {
            $container->definitions()->bind(Request::class, $request, LifetimeEnum::Singleton);

            return $this->errorHandler->handle($request, $runner);
        }

        $scope = 'webrick.request.' . (++$this->requestScopeSeq);

        $result = $container->withinScope(
            $scope,
            fn(): Response => $this->errorHandler->handle($request, $runner),
            [Request::class => $request],
        );
        if (!$result instanceof Response) {
            throw new \RuntimeException('Request scope callback must return Response.');
        }

        return $result;
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
     *
     * @throws \InvalidArgumentException When the Host is illegal, invalid IDN, or contains non-ASCII bytes
     */
    private static function normaliseHost(string $raw): string
    {
        if ($raw === '' || \preg_match('/[\x00-\x20]/', $raw)) {
            throw HttpException::badRequest('Illegal Host header.');
        }
        $host = \strtolower(\rtrim($raw, '.'));

        if (\function_exists('idn_to_ascii') && !\str_contains($host, 'xn--')) {
            $ascii = \idn_to_ascii($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw HttpException::badRequest('Invalid IDN host name.');
            }
            $host = $ascii;
        }
        if (!\preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw HttpException::badRequest('Host contains non-ASCII bytes.');
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
     * @return string Resolved alias file path or null when $cacheLocation is null/empty
     */
    private function aliasFilePath(string $cacheLocation): string
    {
        return (
            \is_dir($cacheLocation)
                ? \rtrim($cacheLocation, '/\\')
                : \dirname($cacheLocation)
        ) . \DIRECTORY_SEPARATOR . self::F_ALIASES;
    }

    /**
     * @return array<string, array{0:string,1:?string}>
     */
    private function aliasPairsFromCache(string $cacheLocation): array
    {
        $aliasFile = $this->aliasFilePath($cacheLocation);
        if (!$this->aliasFileExists($aliasFile)) {
            $this->log->warning('[router] alias cache file not found; URL helpers may be limited', [
                'cache' => $cacheLocation,
            ]);

            return [];
        }

        $blob = $this->requireAliasBlob($aliasFile);
        $pairs = $this->extractAliasPairs($blob);
        if ($pairs === null) {
            $this->log->warning('[router] alias cache has unexpected format; URL helpers may be limited', [
                'file' => $aliasFile,
            ]);

            return [];
        }

        $this->log->info('[router] alias cache loaded', ['file' => $aliasFile, 'count' => \count($pairs)]);

        return $pairs;
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
            'signedUrlConfig' => null,
            'urlBaseUri' => '',
        ];

        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: (bool) $opts['autoSlashRedirect'],
            exposeUrlServices: false,
            signKey: $this->normalizeSignKey($opts['signKey'] ?? null),
            signedDefaultTtl: $this->normalizeSignedDefaultTtl($opts['signedDefaultTtl'] ?? null),
            signedUrlConfig: $this->normalizeSignedUrlConfig($opts['signedUrlConfig'] ?? null),
            urlBaseUri: $this->normalizeUrlBaseUri($opts['urlBaseUri'] ?? null),
        );

        // Let user add routes – matcher is NOT touched in this path.
        Router::setInstance($registrar);
        ($this->register)($registrar);

        // We only need the name/path map for URL helpers.
        return $routes;
    }

    private function createDefaultErrorHandler(): ErrorHandler
    {
        return new ErrorHandler(
            logger: $this->log,
            debug: $this->debug,
            capturePhpErrors: $this->capturePhpErrors,
            requestIdHeader: 'X-Request-Id',
            exceptionMap: [
                RouteNotFoundException::class => StatusEnum::NOT_FOUND->value,
                MethodNotAllowedException::class => StatusEnum::METHOD_NOT_ALLOWED->value,
            ],
        );
    }

    /**
     * @param array<int,callable|object|string> $preGlobal
     * @param array<int,callable|object|string> $postGlobal
     */
    private function createDispatcher(bool $invokerOnMiddleware, array $preGlobal, array $postGlobal): Dispatcher
    {
        return new Dispatcher(
            invoker: $this->invoker,
            useInvoker: $invokerOnMiddleware,
            preGlobalRaw: $preGlobal,
            postGlobalRaw: $postGlobal,
        );
    }

    /**
     * Validate the alias blob structure returned from requireAliasBlob().
     *
     * Expected shape: array with key '_data' whose value is an array.
     *
     * @param mixed $blob Value returned from included alias file
     * @return bool True when $blob is an array and contains an array under '_data'
     */
    /**
     * @return array<string, array{0:string,1:?string}>|null
     */
    private function extractAliasPairs(mixed $blob): ?array
    {
        if (!\is_array($blob) || !\is_array($blob['_data'] ?? null)) {
            return null;
        }

        return \Infocyph\Webrick\Router\Matching\matcher_normalize_alias_pairs($blob['_data']);
    }

    /**
     * @param array<string, array{0:string,1:?string}> $pairs
     */
    private function hydrateAliasPairs(Collection $dst, array $pairs): int
    {
        $added = 0;
        foreach ($pairs as $name => $tuple) {
            if ($name === '') {
                continue;
            }
            $path = $tuple[0];
            $domain = $tuple[1];
            if ($path === '') {
                continue;
            }

            $r = new Route(HttpMethodEnum::GET->value, $path, static fn() => Response::noContent());
            $r = $r->withName($name);
            if (\is_string($domain) && $domain !== '') {
                $r = $r->withDomain($domain);
            }

            try {
                $dst->add($r);
                $added++;
            } catch (LogicException) { /* skip duplicate aliases */
            }
        }

        return $added;
    }

    /**
     * Import configured service providers into the kernel container.
     *
     * @throws ContainerException
     */
    private function importServiceProviders(): void
    {
        if ($this->serviceProviders === []) {
            return;
        }

        $registration = $this->invoker->getContainer()->registration();
        foreach ($this->serviceProviders as $provider) {
            $registration->import($provider);
        }
    }

    /**
     * @return Closure(Request, Closure(Request):Response):Response
     */
    private function lazyTaggedFactoryMiddleware(Container $container, string $id): Closure
    {
        return static function (Request $request, Closure $next) use ($container, $id): Response {
            $middleware = $container->get($id);
            if (!\is_callable($middleware)) {
                throw new \InvalidArgumentException(
                    \sprintf('Tagged middleware [%s] must resolve to a callable.', $id),
                );
            }

            $response = $middleware($request, $next);
            if (!$response instanceof Response) {
                throw new \InvalidArgumentException(
                    \sprintf('Tagged middleware [%s] must return Response.', $id),
                );
            }

            return $response;
        };
    }

    /**
     * @return array<string, array{0:string,1:?string}>
     */
    private function matcherAliasIndex(): array
    {
        if (!method_exists($this->matcher, 'aliasIndex')) {
            return [];
        }

        return \Infocyph\Webrick\Router\Matching\matcher_normalize_alias_pairs(
            $this->matcher->aliasIndex(),
        );
    }

    /**
     * Match the incoming request to a compiled route using the matcher.
     *
     * Returns a two-element tuple: [CompiledRoute, array<string,mixed> vars].
     *
     * @return array{0:CompiledRoute,1:array<string,mixed>} Tuple of matched compiled route and extracted variables
     */
    private function matchRoute(Request $req): array
    {
        // Respect method overrides for routing while preserving explicit HEAD routes.
        $rawMethod = HttpMethodEnum::normalize($req->getMethod());
        $effectiveMethod = HttpMethodEnum::normalize($req->getEffectiveMethod());
        $method = ($rawMethod === HttpMethodEnum::HEAD->value) ? HttpMethodEnum::HEAD->value : $effectiveMethod;
        $uri = $req->getUri();
        $host = self::normaliseHost($uri->getHost());
        $path = $uri->getPath() ?: '/';

        if ($method === '' || $host === '') {
            throw new \RuntimeException('Method and host must be non-empty for matcher.');
        }

        return $this->matcher->match($method, $host, $path);
    }

    /**
     * Append tagged middleware entries from the container to an explicit list.
     *
     * @param array<int,mixed> $explicit
     * @param array<int,string> $tags
     * @return array<int,mixed>
     */
    private function mergeTaggedGlobals(array $explicit, array $tags): array
    {
        if ($tags === []) {
            return $explicit;
        }

        $tagged = [];
        $container = $this->invoker->getContainer();
        $repository = $container->getRepository();
        $definitions = $repository->getFunctionReference();

        foreach ($tags as $tag) {
            if ($tag === '') {
                continue;
            }

            foreach ($repository->getIdsByTag($tag) as $id) {
                if (!\array_key_exists($id, $definitions)) {
                    continue;
                }

                $definition = $definitions[$id];
                $tagged[] = $definition instanceof DirectFactory
                    ? $this->lazyTaggedFactoryMiddleware($container, $id)
                    : $definition;
            }
        }

        return [...$explicit, ...$tagged];
    }

    /**
     * @param array<int,mixed> $entries
     * @return array<int,callable|object|string>
     */
    private function normalizeMiddlewareEntries(array $entries): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            if (\is_string($entry) || \is_object($entry) || (\is_callable($entry) && !\is_string($entry))) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    private function normalizeSignedDefaultTtl(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && $value !== '') {
            return (int) $value;
        }

        return null;
    }

    private function normalizeSignedUrlConfig(mixed $value): ?SignedUrlConfig
    {
        if ($value instanceof SignedUrlConfig) {
            return $value;
        }

        if (\is_array($value) && $value !== []) {
            return SignedUrlConfig::fromArray($value);
        }

        return null;
    }

    private function normalizeSignKey(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function normalizeUrlBaseUri(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<int,mixed> $preGlobal
     * @param array<int,mixed> $postGlobal
     * @return array{
     *   0:array<int,callable|object|string>,
     *   1:array<int,callable|object|string>
     * }
     */
    private function prepareGlobalMiddleware(array $preGlobal, array $postGlobal): array
    {
        if ($preGlobal === [] && $postGlobal === [] && $this->preGlobalTags === [] && $this->postGlobalTags === []) {
            return [[], []];
        }

        $preGlobal = $this->mergeTaggedGlobals($preGlobal, $this->preGlobalTags);
        $postGlobal = $this->mergeTaggedGlobals($postGlobal, $this->postGlobalTags);

        return [
            $this->normalizeMiddlewareEntries($preGlobal),
            $this->normalizeMiddlewareEntries($postGlobal),
        ];
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

    /**
     * @return array<string, array{0:string,1:?string}>
     */
    private function resolveCachedAliasPairs(): array
    {
        $pairs = $this->matcherAliasIndex();
        if ($pairs === [] && $this->routeCache !== null) {
            $pairs = $this->aliasPairsFromCache($this->routeCache);
        }
        if ($pairs === [] && $this->fallbackAliasesFromRegistrar) {
            $this->log->info('[router] alias cache empty; building aliases via registrar (matcher untouched)');

            return $this->buildAliasesViaRegistrar()->aliasIndex();
        }

        return $pairs;
    }

    /* -----------------------------------------------------------------
     * Warm-up / cache priming helpers
     * ----------------------------------------------------------------- */
    /**
     * Warm the router state either from cache or by invoking the registrar.
     *
     * Chooses cache path when the matcher indicates cache-boot support.
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
     */
    private function warmFromCache(): void
    {
        $this->matcher->finalize();

        if ($this->bindUrlServices) {
            $pairs = $this->resolveCachedAliasPairs();
            $aliasOnly = new Collection();
            $this->hydrateAliasPairs($aliasOnly, $pairs);
            ($this->bindUrlServices)($aliasOnly);
            $aliasCount = \count($pairs);
        } else {
            UrlGeneratorRegistry::bindFactory(fn(): UrlGenerator => new UrlGenerator(
                $this->normalizeUrlBaseUri($this->registrarOptions['urlBaseUri'] ?? null),
                $this->resolveCachedAliasPairs(),
                $this->normalizeSignKey($this->registrarOptions['signKey'] ?? null),
                $this->normalizeSignedDefaultTtl($this->registrarOptions['signedDefaultTtl'] ?? null),
                $this->normalizeSignedUrlConfig($this->registrarOptions['signedUrlConfig'] ?? null),
            ));
            $aliasCount = 'lazy';
        }

        $this->log->info('[router] route table ready (hot cache)', [
            'matcher' => $this->matcher::class,
            'cache' => true,
            'mode' => 'cache',
            'aliases' => $aliasCount,
        ]);
    }

    /**
     * Warm by invoking the registrar callback to compile and add routes to the matcher.
     *
     * This method builds a temporary Collection, constructs a Registrar using
     * configured options, executes the user registration callback, compiles the
     * routes and populates the matcher. It also binds URL services when requested.
     *
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
            'signedUrlConfig' => null,
            'urlBaseUri' => '',
        ];

        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: (bool) $opts['autoSlashRedirect'],
            exposeUrlServices: (bool) $opts['exposeUrlServices'],
            signKey: $this->normalizeSignKey($opts['signKey'] ?? null),
            signedDefaultTtl: $this->normalizeSignedDefaultTtl($opts['signedDefaultTtl'] ?? null),
            signedUrlConfig: $this->normalizeSignedUrlConfig($opts['signedUrlConfig'] ?? null),
            urlBaseUri: $this->normalizeUrlBaseUri($opts['urlBaseUri'] ?? null),
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
            'cache' => $this->routeCache !== null,
            'mode' => 'compiled',
        ]);
    }
}
