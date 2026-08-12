<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\CompiledRouteCachePayload;

require_once __DIR__ . '/matcher_functions.php';

/**
 * Produces the canonical scalar metadata used for cache hashing and emission.
 */
final class MatcherCachePayloadNormalizer
{
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof CompiledRoute) {
            return self::normalizeRoute($value);
        }
        if (!\is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $entry) {
            $normalized[$key] = self::normalize($entry);
        }

        return $normalized;
    }

    private static function hasOnlyStringMiddleware(CompiledRoute $route): bool
    {
        return array_all(
            $route->getMiddlewares(),
            static fn(mixed $middleware): bool => \is_string($middleware),
        );
    }

    /**
     * @return array<mixed>|string
     */
    private static function normalizeRoute(CompiledRoute $route): array|string
    {
        $handler = CachedHandlerNormalizer::normalize($route->getHandler());
        if ($handler === null || !self::hasOnlyStringMiddleware($route)) {
            return matcher_serialize_cached_route($route);
        }

        return CompiledRouteCachePayload::validate($route->toCachePayloadWithHandler($handler));
    }
}
