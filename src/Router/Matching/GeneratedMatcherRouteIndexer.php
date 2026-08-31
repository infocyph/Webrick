<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Maintains the generated matcher payload table while routes are indexed. */
final class GeneratedMatcherRouteIndexer
{
    /** @var array<int,mixed> */
    private array $payloads = [];

    public function index(mixed $route): int
    {
        $index = $route instanceof CompiledRoute ? $route->getIndex() : ExecutableRoutePayload::routeIndex($route);
        if ($index === null) {
            throw new \UnexpectedValueException('Generated matcher route is missing its compiled index.');
        }
        if (!array_key_exists($index, $this->payloads)) {
            $this->payloads[$index] = $route instanceof CompiledRoute
                ? MatcherCachePayloadNormalizer::normalize($route)
                : $route;
        }

        return $index;
    }

    /** @return array<int,mixed> */
    public function payloads(): array
    {
        return $this->payloads;
    }
}
