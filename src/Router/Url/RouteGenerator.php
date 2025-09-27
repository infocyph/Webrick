<?php

/**
 * Webrick - Single-responsibility URL generator.
 *
 * Generates URLs for named routes by interpolating path parameters using
 * compiled route metadata. Stores only routes that provide a non-empty name.
 *
 * @package Infocyph\Webrick\Router\Url
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Generate URLs from named compiled routes.
 *
 * Responsibilities:
 * - Keep a map of route-name → CompiledRoute.
 * - Generate a URL path by substituting named parameters into placeholders.
 */
final class RouteGenerator
{
    /**
     * Map of route names to their compiled definitions.
     *
     * @var array<string,CompiledRoute>
     */
    private array $named = [];

    /**
     * Add a compiled route if it has a non-empty name.
     *
     * @param CompiledRoute $route Route to register (stored only if named).
     *
     * @return void
     */
    public function add(CompiledRoute $route): void
    {
        if ($name = $route->getName()) {
            $this->named[$name] = $route;
        }
    }

    /**
     * Generate a URL path for a named route by substituting parameters.
     *
     * Placeholders take the form "{name}" or "{name:regex}". Only the parameter
     * name is considered for substitution; any regex constraint is ignored here
     * and should be enforced at route compilation/matching time.
     *
     * @param string $name The route name.
     * @param array<string,int|float|string> $params Keyed replacements for placeholders.
     *
     * @return string The generated path with placeholders substituted.
     *
     * @throws \InvalidArgumentException If the route name is unknown or a required
     *                                   parameter is missing.
     */
    public function generate(string $name, array $params = []): string
    {
        $r = $this->named[$name]
            ?? throw new \InvalidArgumentException("No route named $name");

        $path = $r->getPath();
        return preg_replace_callback(
            '/\{([A-Za-z_]\w*)(?::[^}]+)?}/',
            fn ($m)
                => $params[$m[1]]
                ?? throw new \InvalidArgumentException("Missing “{$m[1]}” for $name"),
            $path,
        );
    }
}
