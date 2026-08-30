<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\Webrick\Router\Build\Artifact\ArtifactValueCodec;
use UnexpectedValueException;

/**
 * Immutable runtime execution descriptor generated entirely in the build plane.
 */
final readonly class ExecutionPlan
{
    /**
     * @param list<mixed> $middleware
     * @param list<string> $routeArguments
     */
    public function __construct(
        public string $routeId,
        public ExecutionKind $kind,
        public mixed $handler,
        public array $middleware,
        public array $routeArguments,
        public int $capabilities,
    ) {}

    public function requiresRequest(): bool
    {
        return RouteCapability::has($this->capabilities, RouteCapability::REQUEST);
    }

    public function requiresScope(): bool
    {
        return RouteCapability::has($this->capabilities, RouteCapability::SCOPE);
    }

    /** @return array<string,mixed> */
    public function toPayload(): array
    {
        return [
            'route_id' => $this->routeId,
            'kind' => $this->kind->value,
            'handler' => ArtifactValueCodec::encode($this->handler),
            'middleware' => array_map(ArtifactValueCodec::encode(...), $this->middleware),
            'route_arguments' => $this->routeArguments,
            'capabilities' => $this->capabilities,
        ];
    }

    public static function fromPayload(mixed $payload): self
    {
        if (!is_array($payload)) {
            throw new UnexpectedValueException('Invalid execution-plan payload.');
        }

        $routeId = $payload['route_id'] ?? null;
        $kind = $payload['kind'] ?? null;
        $middlewarePayload = $payload['middleware'] ?? null;
        $routeArguments = $payload['route_arguments'] ?? null;
        $capabilities = $payload['capabilities'] ?? null;

        if (
            !is_string($routeId)
            || $routeId === ''
            || !is_string($kind)
            || !is_array($middlewarePayload)
            || !is_array($routeArguments)
            || !is_int($capabilities)
        ) {
            throw new UnexpectedValueException('Malformed execution-plan payload.');
        }

        $executionKind = ExecutionKind::tryFrom($kind)
            ?? throw new UnexpectedValueException("Unknown execution kind '{$kind}'.");

        $middleware = [];
        foreach ($middlewarePayload as $entry) {
            $middleware[] = ArtifactValueCodec::decode($entry);
        }

        $args = [];
        foreach ($routeArguments as $argument) {
            if (!is_string($argument) || $argument === '') {
                throw new UnexpectedValueException('Invalid route-argument metadata.');
            }
            $args[] = $argument;
        }

        return new self(
            routeId: $routeId,
            kind: $executionKind,
            handler: ArtifactValueCodec::decode($payload['handler'] ?? null),
            middleware: $middleware,
            routeArguments: $args,
            capabilities: $capabilities,
        );
    }
}
