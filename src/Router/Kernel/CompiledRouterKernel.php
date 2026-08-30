<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Build\ExecutionKind;
use Infocyph\Webrick\Router\Build\RouterArtifactLoader;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Dispatch\RuntimeDispatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatcherOutcomeAdapter;
use Infocyph\Webrick\Router\Matching\MatchOutcomeType;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Infocyph\Webrick\Router\Url\UrlGeneratorRegistry;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use Psr\Log\LoggerInterface;

/**
 * Strict production kernel. It consumes verified build artifacts and a
 * host-selected InterMix ProductionContainer; registrar/reflection fallback is
 * intentionally impossible from this runtime.
 */
final readonly class CompiledRouterKernel
{
    private ErrorHandler $errorHandler;

    private MatcherOutcomeAdapter $outcomes;

    private RuntimeDispatcher $dispatcher;

    private InterMixRuntime $runtime;

    private function __construct(
        private LoggerInterface $log,
        private CompiledRouterArtifact $artifact,
        MatcherInterface $matcher,
        ProductionContainer $container,
        ?ErrorHandler $errorHandler,
        string $urlBaseUri,
        ?string $signKey,
        ?int $signedDefaultTtl,
        ?SignedUrlConfig $signedUrlConfig,
    ) {
        foreach ($artifact->routes as $route) {
            $matcher->add($route);
        }
        $matcher->finalize();

        $this->runtime = new InterMixRuntime($container);
        $this->dispatcher = new RuntimeDispatcher($this->runtime, $artifact);
        $this->outcomes = new MatcherOutcomeAdapter($matcher);
        $this->errorHandler = $errorHandler ?? new ErrorHandler(
            logger: $log,
            debug: false,
            capturePhpErrors: false,
            exceptionMap: [
                RouteNotFoundException::class => StatusEnum::NOT_FOUND->value,
                MethodNotAllowedException::class => StatusEnum::METHOD_NOT_ALLOWED->value,
            ],
        );

        UrlGeneratorRegistry::bind(new UrlGenerator(
            $urlBaseUri,
            $artifact->aliases,
            $signKey,
            $signedDefaultTtl,
            $signedUrlConfig,
        ));
        UrlGeneratorRegistry::freeze();
        MiddlewareAliases::freeze();
        ConstraintRegistry::freeze();
    }

    public static function fromCompiledArtifact(
        LoggerInterface $log,
        MatcherInterface $matcher,
        ProductionContainer $container,
        string $artifactPath,
        string $environment,
        string $configFingerprint,
        ?ErrorHandler $errorHandler = null,
        string $urlBaseUri = '',
        ?string $signKey = null,
        ?int $signedDefaultTtl = null,
        ?SignedUrlConfig $signedUrlConfig = null,
    ): self {
        $artifact = new RouterArtifactLoader()->load($artifactPath, $environment, $configFingerprint);

        return new self(
            $log,
            $artifact,
            $matcher,
            $container,
            $errorHandler,
            $urlBaseUri,
            $signKey,
            $signedDefaultTtl,
            $signedUrlConfig,
        );
    }

    /** Trusted digest must originate outside the runtime-writable artifact boundary. */
    public static function fromPrevalidatedArtifact(
        LoggerInterface $log,
        MatcherInterface $matcher,
        ProductionContainer $container,
        string $artifactPath,
        string $trustedSha256,
        string $environment,
        string $configFingerprint,
        ?ErrorHandler $errorHandler = null,
        string $urlBaseUri = '',
        ?string $signKey = null,
        ?int $signedDefaultTtl = null,
        ?SignedUrlConfig $signedUrlConfig = null,
    ): self {
        $artifact = new RouterArtifactLoader()->loadPrevalidated(
            $artifactPath,
            $trustedSha256,
            $environment,
            $configFingerprint,
        );

        return new self(
            $log,
            $artifact,
            $matcher,
            $container,
            $errorHandler,
            $urlBaseUri,
            $signKey,
            $signedDefaultTtl,
            $signedUrlConfig,
        );
    }

    public function handle(?Request $request = null): Response
    {
        $request ??= Request::fromGlobals();

        return $this->errorHandler->handle($request, function (Request $current): Response {
            $method = self::routingMethod($current);
            $path = $current->getUri()->getPath() ?: '/';
            $host = $this->artifact->hasDomainRoutes
                ? self::normaliseHost($current->getUri()->getHost())
                : '*';
            $outcome = $this->outcomes->match($method, $host, $path);

            if ($outcome->type === MatchOutcomeType::AUTO_OPTIONS) {
                return self::automaticOptionsResponse($outcome->allowed);
            }
            if ($outcome->type === MatchOutcomeType::METHOD_NOT_ALLOWED) {
                throw new MethodNotAllowedException($method, $path, $outcome->allowed);
            }
            if ($outcome->type === MatchOutcomeType::NOT_FOUND) {
                throw new RouteNotFoundException($method, $path);
            }

            $route = $outcome->requireRoute();
            $plan = $this->artifact->planFor($route);
            $pipeline = $plan->kind === ExecutionKind::MIDDLEWARE_PIPELINE
                || $this->dispatcher->hasGlobalMiddleware();
            $execute = fn(): Response => $this->dispatcher->dispatch($route, $current, $outcome->params);

            if ($plan->requiresScope() || $pipeline) {
                $seeds = ($plan->requiresRequest() || $pipeline) ? [Request::class => $current] : [];
                $response = $this->runtime->withinScope(
                    'webrick.request.' . spl_object_id($current),
                    static fn() => $execute(),
                    $seeds,
                );
                if (!$response instanceof Response) {
                    throw new \RuntimeException('Compiled request scope must return Response.');
                }
            } else {
                $response = $execute();
            }

            // HEAD semantics apply to explicit HEAD routes and GET fallback alike.
            return $method === HttpMethodEnum::HEAD->value
                ? $response->withBody(new Stream(''))
                : $response;
        });
    }

    /** @param list<string> $allowed */
    private static function automaticOptionsResponse(array $allowed): Response
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

    private static function normaliseHost(string $raw): string
    {
        if ($raw === '' || preg_match('/[\\x00-\\x20]/', $raw) === 1) {
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
        if (preg_match('/^[\\x21-\\x7E]+$/', $host) !== 1) {
            throw HttpException::badRequest('Host contains non-ASCII bytes.');
        }

        return $host;
    }

    private static function routingMethod(Request $request): string
    {
        $raw = HttpMethodEnum::normalize($request->getMethod());

        return $raw === HttpMethodEnum::HEAD->value
            ? HttpMethodEnum::HEAD->value
            : HttpMethodEnum::normalize($request->getEffectiveMethod());
    }
}
