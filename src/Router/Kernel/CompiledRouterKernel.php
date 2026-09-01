<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\HeaderPolicy;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Build\ExecutionKind;
use Infocyph\Webrick\Router\Build\ExecutionPlan;
use Infocyph\Webrick\Router\Build\RouterArtifactLoader;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Dispatch\RuntimeDispatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Matching\MatchOutcomeType;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Router\Runtime\RuntimeStageProfiler;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use Infocyph\Webrick\Router\Url\UrlGeneratorRegistry;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use Psr\Log\LoggerInterface;
use Throwable;

/** Strict production kernel backed only by verified compiled artifacts. */
final readonly class CompiledRouterKernel
{
    private RuntimeDispatcher $dispatcher;

    private ErrorHandler $errorHandler;

    private bool $hasGlobalMiddleware;

    private MatcherInterface $matcher;

    private InterMixRuntime $runtime;

    private function __construct(
        LoggerInterface $log,
        private CompiledRouterArtifact $artifact,
        MatcherInterface $matcher,
        ProductionContainer $container,
        ?ErrorHandler $errorHandler,
        string $urlBaseUri,
        ?string $signKey,
        ?int $signedDefaultTtl,
        ?SignedUrlConfig $signedUrlConfig,
        private ?RuntimeStageProfiler $profiler,
    ) {
        if (!$matcher->canBootFromCache()) {
            foreach ($artifact->routes as $route) {
                $matcher->add($route);
            }
        }
        $matcher->finalize();
        $this->profiler?->mark('matcher_boot');

        $this->matcher = $matcher;
        $this->runtime = new InterMixRuntime($container);
        $this->dispatcher = new RuntimeDispatcher($this->runtime, $artifact);
        $this->hasGlobalMiddleware = $this->dispatcher->hasGlobalMiddleware();
        $this->errorHandler = $errorHandler ?? new ErrorHandler(logger: $log, debug: false);
        $this->profiler?->mark('dispatcher_boot');

        UrlGeneratorRegistry::bind(new UrlGenerator($urlBaseUri, $artifact->aliases, $signKey, $signedDefaultTtl, $signedUrlConfig));
        UrlGeneratorRegistry::freeze();
        MiddlewareAliases::freeze();
        ConstraintRegistry::freeze();
        HeaderPolicy::freeze();
        Request::freezeTrustedProxyConfiguration();
        Request::freezeMethodOverrideConfiguration();
        $this->profiler?->mark('kernel_boot');
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
        ?RuntimeStageProfiler $profiler = null,
    ): self {
        $artifact = new RouterArtifactLoader()->load($artifactPath, $environment, $configFingerprint);
        $profiler?->mark('router_artifact_load');

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
            $profiler,
        );
    }

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
        ?RuntimeStageProfiler $profiler = null,
    ): self {
        $artifact = new RouterArtifactLoader()->loadPrevalidated(
            $artifactPath,
            $trustedSha256,
            $environment,
            $configFingerprint,
        );
        $profiler?->mark('router_artifact_load');

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
            $profiler,
        );
    }

    public function handle(?Request $request = null): Response
    {
        try {
            $routing = $request instanceof Request
                ? RoutingInput::fromRequest($request, $this->artifact->hasDomainRoutes)
                : RoutingInput::fromGlobals($this->artifact->hasDomainRoutes);
            $this->profiler?->mark('routing_input');
            $response = $this->dispatchRoutingInput($routing, $request);

            if ($routing->method === HttpMethodEnum::HEAD->value) {
                $response = self::headResponse($response);
            }
            $this->profiler?->mark('response_ready');

            return $response;
        } catch (Throwable $exception) {
            $response = $this->renderException($exception, $request);
            $this->profiler?->mark('error_render');

            if (isset($routing) && $routing->method === HttpMethodEnum::HEAD->value) {
                $response = self::headResponse($response);
            }

            return $response;
        }
    }

    public function handleRuntime(RuntimeRequestContext $context): Response
    {
        $request = null;

        try {
            $response = $this->dispatchRoutingInput($context->routing, $request, $context);
            $this->profiler?->mark('response_ready');

            return $response;
        } catch (Throwable $exception) {
            $response = $this->renderException($exception, $request, $context);
            $this->profiler?->mark('error_render');

            return $response;
        }
    }

    public function requiresHostRouting(): bool
    {
        return $this->artifact->hasDomainRoutes;
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

    private static function headResponse(Response $response): Response
    {
        if (!$response->hasHeader('Content-Length')) {
            $size = $response->getBodySize();
            if ($size !== null) {
                $response = $response->withHeader('Content-Length', (string) $size);
            }
        }

        return $response->withBody('');
    }

    private static function scopeId(RoutingInput $routing, ?RuntimeRequestContext $runtimeContext): string
    {
        return $runtimeContext?->scopeId() ?? 'webrick.request.' . spl_object_id($routing);
    }

    private function dispatchRoutingInput(
        RoutingInput $routing,
        ?Request &$request,
        ?RuntimeRequestContext $runtimeContext = null,
    ): Response {
        $match = $this->matchRoutingInput($routing);
        $this->profiler?->mark('match');

        if (is_int($match)) {
            $routeIndex = $match;
            $vars = [];
        } elseif (is_array($match)) {
            $routeIndex = $match[0];
            $vars = $match[1];
        } else {
            $this->throwOrReturnControlOutcome($match, $routing);
            $response = self::automaticOptionsResponse($match->allowed);
            $this->profiler?->mark('dispatch');

            return $response;
        }

        $plan = $this->artifact->planForIndex($routeIndex);
        $pipeline = $plan->kind === ExecutionKind::MIDDLEWARE_PIPELINE || $this->hasGlobalMiddleware;
        if (!$pipeline && !$plan->requiresRequest()) {
            $response = $this->dispatchWithoutRequest($routing, $plan, $vars, $runtimeContext);
            $this->profiler?->mark('dispatch');

            return $response;
        }
        $request ??= $runtimeContext?->request() ?? Request::fromGlobals();
        $response = $this->dispatchWithRequest($routing, $plan, $request, $vars, $runtimeContext);
        $this->profiler?->mark('dispatch');

        return $response;
    }

    /** @param array<string,string> $vars */
    private function dispatchWithoutRequest(
        RoutingInput $routing,
        ExecutionPlan $plan,
        array $vars,
        ?RuntimeRequestContext $runtimeContext,
    ): Response {
        if (!$plan->requiresScope()) {
            return match ($plan->terminalKind) {
                ExecutionKind::DIRECT_ZERO_ARG => $this->dispatcher->dispatchDirectZeroArg($plan),
                ExecutionKind::DIRECT_ROUTE_ARGS => $this->dispatcher->dispatchDirectRouteArgs($plan, $vars),
                default => $this->dispatcher->dispatchWithoutRequest($plan, $vars),
            };
        }
        $response = $this->runtime->withinScope(
            self::scopeId($routing, $runtimeContext),
            fn() => $this->dispatcher->dispatchWithoutRequest($plan, $vars),
        );
        if (!$response instanceof Response) {
            throw new \RuntimeException('Compiled request scope must return Response.');
        }

        return $response;
    }

    /** @param array<string,string> $vars */
    private function dispatchWithRequest(
        RoutingInput $routing,
        ExecutionPlan $plan,
        Request $request,
        array $vars,
        ?RuntimeRequestContext $runtimeContext,
    ): Response {
        $requiresScope = $plan->requiresScope() || $this->dispatcher->pipelineRequiresScope($plan);
        if (!$requiresScope) {
            return $this->dispatcher->dispatch($plan, $request, $vars);
        }
        $response = $this->runtime->withinScope(
            self::scopeId($routing, $runtimeContext),
            fn() => $this->dispatcher->dispatch($plan, $request, $vars),
            [Request::class => $request],
        );
        if (!$response instanceof Response) {
            throw new \RuntimeException('Compiled request scope must return Response.');
        }

        return $response;
    }

    /** @return int|array{0:int,1:array<string,string>}|MatchOutcome */
    private function matchRoutingInput(RoutingInput $routing): int|array|MatchOutcome
    {
        if ($routing->method === '' || $routing->host === '' || $routing->path === '') {
            throw new \LogicException('Routing input must be canonical before compiled matching.');
        }

        return $this->matcher->matchCompiled($routing->method, $routing->host, $routing->path);
    }

    private function renderException(
        Throwable $exception,
        ?Request $request,
        ?RuntimeRequestContext $runtimeContext = null,
    ): Response {
        if (!$request instanceof Request) {
            try {
                $request = $runtimeContext?->request() ?? Request::fromGlobals();
            } catch (Throwable) {
                $request = Request::fake();
            }
        }

        return $this->errorHandler->handle(
            $request,
            static function (Request $activeRequest) use ($exception): Response {
                unset($activeRequest);

                throw $exception;
            },
        );
    }

    private function throwOrReturnControlOutcome(MatchOutcome $outcome, RoutingInput $routing): void
    {
        if ($outcome->type === MatchOutcomeType::AUTO_OPTIONS) {
            return;
        }
        if ($outcome->type === MatchOutcomeType::METHOD_NOT_ALLOWED) {
            throw new MethodNotAllowedException($routing->method, $routing->path, $outcome->allowed);
        }

        throw new RouteNotFoundException($routing->method, $routing->path);
    }
}
