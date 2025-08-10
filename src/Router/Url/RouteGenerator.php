<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Single-responsibility URL generator.
 *
 * Feed it with *named* CompiledRoutes and call ->generate($name, $params).
 */
final class RouteGenerator
{
    /** @var array<string,CompiledRoute> */
    private array $named = [];

    public function add(CompiledRoute $route): void
    {
        if ($name = $route->getName()) {
            $this->named[$name] = $route;
        }
    }

    /**
     * @param array<string,string|int|float> $params
     */
    public function generate(string $name, array $params = []): string
    {
        $r = $this->named[$name]
            ?? throw new \InvalidArgumentException("No route named $name");

        $path = $r->getPath();
        return preg_replace_callback(
            '/\{([A-Za-z_]\w*)(?::[^}]+)?}/',
            fn($m)
                => $params[$m[1]]
                ?? throw new \InvalidArgumentException("Missing “{$m[1]}” for $name"),
            $path,
        );
    }
}
