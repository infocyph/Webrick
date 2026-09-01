<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\InterMix\DI\Support\DirectFactory;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\Dispatcher;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Psr\Log\LoggerInterface;

/**
 * Development/registrar kernel.
 *
 * The host owns the InterMix graph and supplies its Invoker. This kernel never
 * creates/selects a container, imports providers, infers an environment, or
 * boots from production route-cache artifacts. Compiled production traffic is
 * handled by CompiledRouterKernel.
 */
final class RouterKernel
{
    private readonly Dispatcher $dispatcher;

    private readonly ErrorHandler $errorHandler;

    private int $requestScopeSeq = 0;

    /**
     * @param array<string,mixed> $registrarOptions
     * @param array<int,mixed> $preGlobal
     * @param array<int,mixed> $postGlobal
     * @param array<int,string> $preGlobalTags
     * @param array<int,string> $postGlobalTags
     */
    public function __construct(
        private readonly LoggerInterface $log,
        private readonly MatcherInterface $matcher,
        private readonly Closure $register,
        private readonly Invoker $invoker,
        private readonly array $registrarOptions = [],
        array $preGlobal = [],
        array $postGlobal = [],
        bool $invokerOnMiddleware = false,
        ?ErrorHandler $errorHandler = null,
        private readonly ?Closure $bindUrlServices = null,
        private readonly array $preGlobalTags = ['webrick.middleware.pre'],
        private readonly array $postGlobalTags = ['webrick.middleware.post'],
        private readonly bool $debug = false,
    ) {
        $this->warm();
        [$preGlobal, $postGlobal] = $this->prepareGlobalMiddleware($preGlobal, $postGlobal);
        $this->dispatcher = new Dispatcher(
            invoker: $this->invoker,
            useInvoker: $invokerOnMiddleware,
            preGlobalRaw: $preGlobal,
            postGlobalRaw: $postGlobal,
        );
        $this->errorHandler = $errorHandler ?? $this->createDefaultErrorHandler();
    }

    /**
     * Explicit registrar/development bootstrap.
     *
     * Production applications should compile a Webrick artifact and construct
     * CompiledRouterKernel with the host-selected ProductionContainer.
     *
     * @param array<string,mixed> $registrarOptions
     * @param array<int,mixed> $preGlobal
     * @param array<int,mixed> $postGlobal
     * @param array<int,string> $preGlobalTags
     * @param array<int,string> $postGlobalTags
     */
    public static function bootWithRegistrar(
        LoggerInterface $log,
        MatcherInterface $matcher,
        Closure $register,
        Invoker $invoker,
        array $registrarOptions = [],
        array $preGlobal = [],
        array $postGlobal = [],
        bool $invokerOnMiddleware = false,
        ?ErrorHandler $errorHandler = null,
        ?Closure $bindUrlServices = null,
        array $preGlobalTags = ['webrick.middleware.pre'],
        array $postGlobalTags = ['webrick.middleware.post'],
        bool $debug = false,
    ): self {
        return new self(
            log: $log,
            matcher: $matcher,
            register: $register,
            invoker: $invoker,
            registrarOptions: $registrarOptions,
            preGlobal: $preGlobal,
            postGlobal: $postGlobal,
            invokerOnMiddleware: $invokerOnMiddleware,
            errorHandler: $errorHandler,
            bindUrlServices: $bindUrlServices,
            preGlobalTags: $preGlobalTags,
            postGlobalTags: $postGlobalTags,
            debug: $debug,
        );
    }

    public function handle(?Request $request = null): Response
    {
        $request ??= Request::fromGlobals();
        $runner = function (Request $req): Response {
            try {
                [$route, $vars] = $this->matchRoute($req);
            } catch (MethodNotAllowedException $exception) {
                if (self::routingMethod($req) !== HttpMethodEnum::OPTIONS->value) {
                    throw $exception;
                }

                return $this->automaticOptionsResponse($exception->allowed());
            }

            return $this->dispatcher->dispatch($route, $req, $vars);
        };

        $scope = 'webrick.request.' . (++$this->requestScopeSeq);
        $result = $this->invoker->getContainer()->withinScope(
            $scope,
            fn(): Response => $this->errorHandler->handle($request, $runner),
            [Request::class => $request],
        );

        if (!$result instanceof Response) {
            throw new \RuntimeException('Request scope callback must return Response.');
        }

        return $result;
    }

    private static function normaliseHost(string $raw): string
    {
        if ($raw === '' || preg_match('/[\x00-\x20]/', $raw)) {
            throw HttpException::badRequest('Illegal Host header.');
        }

        $host = strtolower(rtrim($raw, '.'));
        if (function_exists('idn_to_ascii') && !str_contains($host, 'xn--')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw HttpException::badRequest('Invalid IDN host name.');
            }
            $host = $ascii;
        }
        if (!preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw HttpException::badRequest('Host contains non-ASCII bytes.');
        }

        return $host;
    }

    private static function routingMethod(Request $req): string
    {
        $rawMethod = HttpMethodEnum::normalize($req->getMethod());
        $effectiveMethod = HttpMethodEnum::normalize($req->getEffectiveMethod());

        return $rawMethod === HttpMethodEnum::HEAD->value
            ? HttpMethodEnum::HEAD->value
            : $effectiveMethod;
    }

    /** @param array<int,string> $allowed */
    private function automaticOptionsResponse(array $allowed): Response
    {
        $methods = [];
        foreach ($allowed as $method) {
            $method = HttpMethodEnum::normalize($method);
            if ($method !== '') {
                $methods[$method] = true;
            }
        }
        if (isset($methods[HttpMethodEnum::GET->value])) {
            $methods[HttpMethodEnum::HEAD->value] = true;
        }
        $methods[HttpMethodEnum::OPTIONS->value] = true;

        return Response::noContent(['Allow' => implode(', ', array_keys($methods))]);
    }

    private function createDefaultErrorHandler(): ErrorHandler
    {
        return new ErrorHandler(
            logger: $this->log,
            debug: $this->debug,
            requestIdHeader: 'X-Request-Id',
        );
    }

    /**
     * @return Closure(Request, Closure(Request):Response):Response
     */
    private function lazyTaggedFactoryMiddleware(Container $container, string $id): Closure
    {
        return static function (Request $request, Closure $next) use ($container, $id): Response {
            $middleware = $container->get($id);
            if (!is_callable($middleware)) {
                throw new \InvalidArgumentException(
                    sprintf('Tagged middleware [%s] must resolve to a callable.', $id),
                );
            }

            $response = $middleware($request, $next);
            if (!$response instanceof Response) {
                throw new \InvalidArgumentException(
                    sprintf('Tagged middleware [%s] must return Response.', $id),
                );
            }

            return $response;
        };
    }

    /**
     * @return array{0:CompiledRoute,1:array<string,mixed>}
     */
    private function matchRoute(Request $req): array
    {
        $method = self::routingMethod($req);
        $uri = $req->getUri();
        $host = self::normaliseHost($uri->getHost());
        $path = $uri->getPath() ?: '/';

        if ($method === '' || $host === '') {
            throw new \RuntimeException('Method and host must be non-empty for matcher.');
        }

        return $this->matcher->match($method, $host, $path);
    }

    /**
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
                if (!array_key_exists($id, $definitions)) {
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
            if (is_string($entry) || is_object($entry) || (is_callable($entry) && !is_string($entry))) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    private function normalizeSignedDefaultTtl(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' ? (int) $value : null;
    }

    private function normalizeSignedUrlConfig(mixed $value): ?SignedUrlConfig
    {
        if ($value instanceof SignedUrlConfig) {
            return $value;
        }

        return is_array($value) && $value !== [] ? SignedUrlConfig::fromArray($value) : null;
    }

    private function normalizeSignKey(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function normalizeUrlBaseUri(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<int,mixed> $preGlobal
     * @param array<int,mixed> $postGlobal
     * @return array{0:array<int,callable|object|string>,1:array<int,callable|object|string>}
     */
    private function prepareGlobalMiddleware(array $preGlobal, array $postGlobal): array
    {
        if ($preGlobal === [] && $postGlobal === [] && $this->preGlobalTags === [] && $this->postGlobalTags === []) {
            return [[], []];
        }

        return [
            $this->normalizeMiddlewareEntries($this->mergeTaggedGlobals($preGlobal, $this->preGlobalTags)),
            $this->normalizeMiddlewareEntries($this->mergeTaggedGlobals($postGlobal, $this->postGlobalTags)),
        ];
    }

    private function warm(): void
    {
        $routes = new Collection();
        $options = $this->registrarOptions + [
            'autoSlashRedirect' => false,
            'exposeUrlServices' => false,
            'signKey' => null,
            'signedDefaultTtl' => null,
            'signedUrlConfig' => null,
            'urlBaseUri' => '',
        ];

        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: (bool) $options['autoSlashRedirect'],
            exposeUrlServices: (bool) $options['exposeUrlServices'],
            signKey: $this->normalizeSignKey($options['signKey'] ?? null),
            signedDefaultTtl: $this->normalizeSignedDefaultTtl($options['signedDefaultTtl'] ?? null),
            signedUrlConfig: $this->normalizeSignedUrlConfig($options['signedUrlConfig'] ?? null),
            urlBaseUri: $this->normalizeUrlBaseUri($options['urlBaseUri'] ?? null),
        );

        Router::withScopedInstance(
            $registrar,
            fn(Registrar $active): mixed => ($this->register)($active),
        );

        $compiled = $routes->compile()->all();
        if ($compiled === []) {
            throw new \RuntimeException('Registration produced an empty route table.');
        }

        foreach ($compiled as $route) {
            $this->matcher->add($route);
        }
        $this->matcher->finalize();

        if ($this->bindUrlServices !== null) {
            ($this->bindUrlServices)($routes);
        }

        $this->log->info('[router] development route table ready', [
            'count' => count($compiled),
            'matcher' => $this->matcher::class,
            'mode' => 'registrar',
        ]);
    }
}
