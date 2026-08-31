<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build\Artifact;

use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Structural cached-route record with executable values isolated behind ArtifactValueCodec. */
final class ExecutableRoutePayload
{
    private const int VERSION = 1;

    private function __construct() {}

    public static function decode(mixed $payload): CompiledRoute
    {
        if (!is_array($payload) || ($payload['version'] ?? null) !== self::VERSION) {
            throw new \UnexpectedValueException('Invalid executable route payload.');
        }
        $metadata = MatcherRouteMetadata::decode($payload['route'] ?? null);
        $handler = self::decodeHandler($payload['handler'] ?? null);
        $middlewarePayload = $payload['middleware'] ?? null;
        if (!is_array($middlewarePayload) || !array_is_list($middlewarePayload)) {
            throw new \UnexpectedValueException('Invalid executable route middleware payload.');
        }

        $middleware = self::decodeMiddleware($middlewarePayload);

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

    /**
     * @return array{version:int,route:array<mixed>,handler:array{kind:string,value:mixed},middleware:list<array{kind:string,value:mixed}>}
     */
    public static function encode(CompiledRoute $route): array
    {
        return [
            'version' => self::VERSION,
            'route' => MatcherRouteMetadata::encode($route),
            'handler' => ArtifactValueCodec::encode($route->getHandler()),
            'middleware' => array_map(ArtifactValueCodec::encode(...), $route->getMiddlewares()),
        ];
    }

    public static function routeIndex(mixed $payload): ?int
    {
        if (!is_array($payload) || !is_array($payload['route'] ?? null)) {
            return null;
        }

        $index = $payload['route'][10] ?? null;

        return is_int($index) ? $index : null;
    }

    /**
     * @return array{object|string,string}|string|callable
     */
    private static function decodeHandler(mixed $payload): array|string|callable
    {
        $handler = ArtifactValueCodec::decode($payload);
        if (is_string($handler) || is_callable($handler)) {
            return $handler;
        }
        if (is_array($handler) && count($handler) === 2 && (is_object($handler[0]) || is_string($handler[0])) && is_string($handler[1])) {
            return [$handler[0], $handler[1]];
        }

        throw new \UnexpectedValueException('Invalid executable route handler.');
    }

    /**
     * @param list<mixed> $payload
     * @return list<object|string|array{object|string,string}>
     */
    private static function decodeMiddleware(array $payload): array
    {
        $middleware = [];
        foreach ($payload as $entry) {
            $descriptor = ArtifactValueCodec::decode($entry);
            if (is_string($descriptor) || is_object($descriptor)) {
                $middleware[] = $descriptor;

                continue;
            }
            if (is_array($descriptor) && count($descriptor) === 2 && (is_object($descriptor[0]) || is_string($descriptor[0])) && is_string($descriptor[1])) {
                $middleware[] = [$descriptor[0], $descriptor[1]];

                continue;
            }

            throw new \UnexpectedValueException('Invalid executable route middleware descriptor.');
        }

        return $middleware;
    }
}
