<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Build\ExecutionKind;
use Infocyph\Webrick\Router\Build\ExecutionPlan;
use Infocyph\Webrick\Router\Build\RouterArtifactLoader;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Dispatch\RuntimeDispatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatchOutcomeType;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Infocyph\Webrick\Router\Url\UrlGeneratorRegistry;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Strict production kernel. It consumes verified build artifacts and a
 * host-selected InterMix ProductionContainer; registrar/reflection fallback is
 * intentionally impossible from this runtime.
 */
final readonly class CompiledRouterKernel
{
    private RuntimeDispatcher $dispatcher;

    private ErrorHandler $errorHandler;

    private bool $hasGlobalMiddleware;

    private MatcherInterface $matcher;

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

        $this->matcher = $matcher;
        $this->runtime = new InterMixRuntime($container);
        $this->dispatcher = new RuntimeDispatcher($this->runtime, $artifact);
        $this->hasGlobalMiddleware = $this->dispatcher->hasGlobalMiddleware();
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
        try {
            $routing = $request instanceof Request
                ? RoutingInput::fromRequest($request, $this->artifact->hasDomainRoutes)
                : RoutingInput::fromGlobals($this->artifact->hasDomainRoutes);

            return $this->dispatchRoutingInput($routing, $request);
        } catch (Throwable $exception) {
            return $this->renderException($exception, $request);
        }
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

    private function dispatchRoutingInput(RoutingInput $routing, ?Request &$request): Response
    {
        $outcome = $this->matcher->matchOutcome($routing->method, $routing->host, $routing->path);

        if ($outcome->type === MatchOutcomeType::AUTO_OPTIONS) {
            return self::automaticOptionsResponse($outcome->allowed);
        }
        if ($outcome->type === MatchOutcomeType::METHOD_NOT_ALLOWED) {
            throw new MethodNotAllowedException($routing->method, $routing->path, $outcome->allowed);
        }
        if ($outcome->type === MatchOutcomeType::NOT_FOUND) {
            throw new RouteNotFoundException($routing->method, $routing->path);
        }

        $route = $outcome->requireRoute();
        $plan = $this->artifact->planFor($route);
        $pipeline = $plan->kind === ExecutionKind::MIDDLEWARE_PIPELINE
            || $this->hasGlobalMiddleware;

        if (!$pipeline && !$plan->requiresRequest()) {
            $response = $this->dispatchWithoutRequest($routing, $plan, $outcome->params);
        } else {
            $request ??= Request::fromGlobals();
            $response = $this->dispatchWithRequest($routing, $plan, $request, $outcome->params, $pipeline);
        }

        return $routing->method === HttpMethodEnum::HEAD->value
            ? $response->withBody(new Stream(''))
            : $response;
    }

    /** @param array<string,string> $vars */
    private function dispatchWithRequest(
        RoutingInput $routing,
        ExecutionPlan $plan,
        Request $request,
        array $vars,
        bool $pipeline,
    ): Response {
        if (!$plan->requiresScope() && !$pipeline) {
            return $this->dispatcher->dispatch($plan, $request, $vars);
        }

        $response = $this->runtime->withinScope(
            'webrick.request.' . spl_object_id($routing),
            fn() => $this->dispatcher->dispatch($plan, $request, $vars),
            [Request::class => $request],
        );
        if (!$response instanceof Response) {
            throw new \RuntimeException('Compiled request scope must return Response.');
        }

        return $response;
    }

    /** @param array<string,string> $vars */
    private function dispatchWithoutRequest(RoutingInput $routing, ExecutionPlan $plan, array $vars): Response
    {
        if (!$plan->requiresScope()) {
            return match ($plan->terminalKind) {
                ExecutionKind::DIRECT_ZERO_ARG => $this->dispatcher->dispatchDirectZeroArg($plan),
                ExecutionKind::DIRECT_ROUTE_ARGS => $this->dispatcher->dispatchDirectRouteArgs($plan, $vars),
                default => $this->dispatcher->dispatchWithoutRequest($plan, $vars),
            };
        }

        $response = $this->runtime->withinScope(
            'webrick.request.' . spl_object_id($routing),
            fn() => $this->dispatcher->dispatchWithoutRequest($plan, $vars),
        );
        if (!$response instanceof Response) {
            throw new \RuntimeException('Compiled request scope must return Response.');
        }

        return $response;
    }

    private function renderException(Throwable $exception, ?Request $request): Response
    {
        if (!$request instanceof Request) {
            try {
                $request = Request::fromGlobals();
            } catch (Throwable) {
                $request = Request::fake();
            }
        }

        return $this->errorHandler->handle(
            $request,
            static fn(Request $_): Response => throw $exception,
        );
    }
}
