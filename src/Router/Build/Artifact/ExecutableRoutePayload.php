<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build\Artifact;

use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Structural cached-route record with executable values isolated behind ArtifactValueCodec. */
final class ExecutableRoutePayload
{
    private const int VERSION = 1;

    private function __construct() {}

    /** @return array{version:int,route:array<mixed>,handler:array{kind:string,value:mixed},middleware:list<array{kind:string,value:mixed}>} */
    public static function encode(CompiledRoute $route): array
    {
        return [
            'version' => self::VERSION,
            'route' => MatcherRouteMetadata::encode($route),
            'handler' => ArtifactValueCodec::encode($route->getHandler()),
            'middleware' => array_map(ArtifactValueCodec::encode(...), array_values($route->getMiddlewares())),
        ];
    }

    public static function decode(mixed $payload): CompiledRoute
    {
        if (!is_array($payload) || ($payload['version'] ?? null) !== self::VERSION) {
            throw new \UnexpectedValueException('Invalid executable route payload.');
        }
        $metadata = MatcherRouteMetadata::decode($payload['route'] ?? null);
        $handler = ArtifactValueCodec::decode($payload['handler'] ?? null);
        $middlewarePayload = $payload['middleware'] ?? null;
        if (!is_array($middlewarePayload) || !array_is_list($middlewarePayload)) {
            throw new \UnexpectedValueException('Invalid executable route middleware payload.');
        }

        $middleware = array_map(ArtifactValueCodec::decode(...), $middlewarePayload);

        return new CompiledRoute(
            method: $metadata->getMethod(),
            path: $metadata->getPath(),
            handler: $handler,
            domain: $metadata->getDomain(),
            middleware: $middleware,
            name: $metadata->getName(),
            dynamic: $metadata->isDynamic(),
            regex: $metadata->getRegex(),
            variables: $metadata->getVariables(),
            index: $metadata->getIndex(),
            corsPolicy: $metadata->getCorsPolicy(),
            produces: $metadata->getProduces(),
            segments: $metadata->getSegments(),
        );
    }

    public static function routeIndex(mixed $payload): ?int
    {
        if (!is_array($payload) || !is_array($payload['route'] ?? null)) {
            return null;
        }

        $index = $payload['route'][10] ?? null;

        return is_int($index) ? $index : null;
    }
}
