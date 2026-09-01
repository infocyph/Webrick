<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\Webrick\Router\Build\Artifact\ArtifactValueCodec;
use UnexpectedValueException;

/** Immutable runtime execution descriptor generated entirely in the build plane. */
final readonly class ExecutionPlan
{
    /**
     * @param list<mixed> $middleware
     * @param list<string> $routeArguments
     */
    public function __construct(
        public string $routeId,
        public ExecutionKind $kind,
        public ExecutionKind $terminalKind,
        public mixed $handler,
        public array $middleware,
        public array $routeArguments,
        public int $capabilities,
    ) {
        if ($routeId === '') {
            throw new \InvalidArgumentException('Execution-plan route ID must not be empty.');
        }
        if ($terminalKind === ExecutionKind::MIDDLEWARE_PIPELINE) {
            throw new \InvalidArgumentException('Execution-plan terminal kind cannot be a middleware pipeline.');
        }
        if ($kind !== ExecutionKind::MIDDLEWARE_PIPELINE && $kind !== $terminalKind) {
            throw new \InvalidArgumentException('Non-pipeline execution kind must equal its terminal kind.');
        }
        if ($terminalKind !== ExecutionKind::COMPILED_INVOKE && !is_callable($handler)) {
            throw new \InvalidArgumentException('Direct execution plans require a callable handler.');
        }
    }

    public static function fromPayload(mixed $payload): self
    {
        if (!is_array($payload)) {
            throw new UnexpectedValueException('Invalid execution-plan payload.');
        }

        $routeId = $payload['route_id'] ?? null;
        $kind = $payload['kind'] ?? null;
        $terminalKind = $payload['terminal_kind'] ?? null;
        $middlewarePayload = $payload['middleware'] ?? null;
        $routeArguments = $payload['route_arguments'] ?? null;
        $capabilities = $payload['capabilities'] ?? null;
        if (
            !is_string($routeId) || $routeId === ''
            || !is_string($kind)
            || !is_string($terminalKind)
            || !is_array($middlewarePayload)
            || !is_array($routeArguments)
            || !is_int($capabilities)
        ) {
            throw new UnexpectedValueException('Malformed execution-plan payload.');
        }

        $executionKind = ExecutionKind::tryFrom($kind)
            ?? throw new UnexpectedValueException("Unknown execution kind '{$kind}'.");
        $terminalExecutionKind = ExecutionKind::tryFrom($terminalKind)
            ?? throw new UnexpectedValueException("Unknown terminal execution kind '{$terminalKind}'.");

        $middleware = array_map(ArtifactValueCodec::decode(...), array_values($middlewarePayload));
        $arguments = [];
        foreach ($routeArguments as $argument) {
            if (!is_string($argument) || $argument === '') {
                throw new UnexpectedValueException('Invalid route-argument metadata.');
            }
            $arguments[] = $argument;
        }

        try {
            return new self(
                routeId: $routeId,
                kind: $executionKind,
                terminalKind: $terminalExecutionKind,
                handler: ArtifactValueCodec::decode($payload['handler'] ?? null),
                middleware: $middleware,
                routeArguments: $arguments,
                capabilities: $capabilities,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new UnexpectedValueException('Invalid execution-plan invariant.', 0, $exception);
        }
    }

    public function requiresRequest(): bool
    {
        return RouteCapability::has($this->capabilities, RouteCapability::REQUEST);
    }

    public function requiresScope(): bool
    {
        return RouteCapability::has($this->capabilities, RouteCapability::SCOPE);
    }

    /**
     * @return array<mixed>|callable|string|null
     */
    public function resolverSpec(): array|callable|string|null
    {
        if ($this->handler === null || is_string($this->handler) || is_callable($this->handler)) {
            return $this->handler;
        }
        if (is_array($this->handler)) {
            return $this->handler;
        }

        throw new UnexpectedValueException('Execution-plan handler is not a resolver descriptor.');
    }

    /** @return array<string,mixed> */
    public function toPayload(): array
    {
        return [
            'route_id' => $this->routeId,
            'kind' => $this->kind->value,
            'terminal_kind' => $this->terminalKind->value,
            'handler' => ArtifactValueCodec::encode($this->handler),
            'middleware' => array_map(ArtifactValueCodec::encode(...), $this->middleware),
            'route_arguments' => $this->routeArguments,
            'capabilities' => $this->capabilities,
        ];
    }
}
