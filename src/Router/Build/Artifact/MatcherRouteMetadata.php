<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build\Artifact;

use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Scalar route metadata required by matching and route-level response policy only. */
final class MatcherRouteMetadata
{
    private const string INERT_HANDLER = '__webrick_matcher_only__';

    private function __construct() {}

    public static function decode(mixed $payload): CompiledRoute
    {
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Invalid matcher route metadata payload.');
        }

        return CompiledRoute::fromCachePayload($payload);
    }

    /**
     * @return array<mixed>
     * @param CompiledRoute $route
     */
    public static function encode(CompiledRoute $route): array
    {
        $cors = $route->getCorsPolicy();
        $produces = $route->getProduces();

        return [
            CompiledRoute::CACHE_PAYLOAD_VERSION,
            $route->getMethod(),
            $route->getPath(),
            self::INERT_HANDLER,
            $route->getDomain(),
            [],
            $route->getName(),
            $route->isDynamic(),
            $route->getRegex(),
            $route->getVariables(),
            $route->getIndex(),
            $cors instanceof Cors ? [
                'origins' => array_values($cors->origins),
                'methods' => $cors->methods,
                'headers' => $cors->headers,
                'exposeHeaders' => $cors->exposeHeaders,
                'maxAgeSeconds' => $cors->maxAgeSeconds,
                'allowCredentials' => $cors->allowCredentials,
                'allowPrivateNetwork' => $cors->allowPrivateNetwork,
            ] : null,
            $produces instanceof Produces ? [
                'types' => array_values($produces->types),
                'charsets' => $produces->charsets === null ? null : array_values($produces->charsets),
            ] : null,
            $route->getSegments(),
        ];
    }
}
