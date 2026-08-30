<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Dispatch;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Build\ExecutionKind;
use Infocyph\Webrick\Router\Build\ExecutionPlan;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use JsonSerializable;
use UnexpectedValueException;

/** Runtime-plane dispatcher for already-compiled route execution plans. */
final class RuntimeDispatcher
{
    /** @var array<string,CompiledMiddlewarePipeline> */
    private array $pipelines = [];

    /** @var list<mixed> */
    private array $postGlobal;

    /** @var list<mixed> */
    private array $preGlobal;

    public function __construct(
        private readonly InterMixRuntime $runtime,
        private readonly CompiledRouterArtifact $artifact,
    ) {
        $this->preGlobal = $this->withTagged($artifact->preGlobal, $artifact->preGlobalTags);
        $this->postGlobal = $this->withTagged($artifact->postGlobal, $artifact->postGlobalTags);
    }

    /** @param array<string,string> $vars */
    public function dispatch(CompiledRoute $route, Request $request, array $vars): Response
    {
        $plan = $this->artifact->planFor($route);
        $middleware = [...$this->preGlobal, ...$plan->middleware, ...$this->postGlobal];
        if ($middleware === []) {
            return $this->invokeTerminal($plan, $request, $vars);
        }

        // One canonical route-parameter bag is attached only because middleware
        // already requires a full Request. No duplicate internal aliases exist.
        $pipelineRequest = $vars === [] ? $request : $request->withAttributes(['route_params' => $vars]);
        $routeId = $plan->routeId;
        $this->pipelines[$routeId] ??= new CompiledMiddlewarePipeline(
            $middleware,
            fn(Request $current): Response => $this->invokeTerminal(
                $plan,
                $current,
                $this->routeVariables($current),
            ),
            $this->runtime,
        );

        return $this->pipelines[$routeId]->handle($pipelineRequest);
    }

    public function hasGlobalMiddleware(): bool
    {
        return $this->preGlobal !== [] || $this->postGlobal !== [];
    }

    /** @param array<string,string> $vars */
    private function invokeTerminal(ExecutionPlan $plan, Request $request, array $vars): Response
    {
        /** @var callable $direct */
        $direct = $plan->handler;
        $result = match ($plan->terminalKind) {
            ExecutionKind::DIRECT_ZERO_ARG => $direct(),
            ExecutionKind::DIRECT_ROUTE_ARGS => $direct(...$this->orderedRouteArguments($plan, $vars)),
            ExecutionKind::DIRECT_REQUEST => $direct($request),
            ExecutionKind::COMPILED_INVOKE => $this->runtime->resolveNow(
                $plan->handler,
                $plan->requiresRequest() ? ($vars + ['request' => $request]) : $vars,
            ),
            ExecutionKind::MIDDLEWARE_PIPELINE => throw new UnexpectedValueException(
                'Middleware pipeline cannot be used as a terminal execution kind.',
            ),
        };

        return $result instanceof Response
            ? $result
            : Response::json($this->normalizeResponsePayload($result));
    }

    /** @param array<string,string> $vars @return list<string> */
    private function orderedRouteArguments(ExecutionPlan $plan, array $vars): array
    {
        $arguments = [];
        foreach ($plan->routeArguments as $name) {
            if (!array_key_exists($name, $vars)) {
                throw new UnexpectedValueException("Missing compiled route argument '{$name}'.");
            }
            $arguments[] = $vars[$name];
        }

        return $arguments;
    }

    /** @return array<array-key,mixed>|bool|float|int|JsonSerializable|string|null */
    private function normalizeResponsePayload(mixed $value): array|bool|float|int|JsonSerializable|string|null
    {
        if (is_array($value) || $value instanceof JsonSerializable) {
            return $value;
        }
        if ($value === null || is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        return get_debug_type($value);
    }

    /** @return array<string,string> */
    private function routeVariables(Request $request): array
    {
        $value = $request->getAttribute('route_params', []);
        if (!is_array($value)) {
            return [];
        }

        $variables = [];
        foreach ($value as $name => $item) {
            if (is_string($name) && is_string($item)) {
                $variables[$name] = $item;
            }
        }

        return $variables;
    }

    /** @param list<mixed> $explicit @param list<string> $tags @return list<mixed> */
    private function withTagged(array $explicit, array $tags): array
    {
        foreach ($tags as $tag) {
            foreach ($this->runtime->findByTag($tag) as $middleware) {
                $explicit[] = $middleware;
            }
        }

        return $explicit;
    }
}
