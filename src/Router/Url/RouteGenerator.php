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

use Infocyph\Webrick\Router\Route\Collection;

/**
 * Generate URLs for named routes.
 *
 * Thin wrapper around UrlGenerator kept for route-oriented naming.
 */
final class RouteGenerator
{
    private UrlGenerator $urlGenerator;

    public function __construct(string $baseUri, Collection $routes)
    {
        $this->urlGenerator = new UrlGenerator(\rtrim($baseUri, '/'), $routes);
    }

    /**
     * Generate a route URL with optional query and absolute mode.
     *
     * @param string $name Route name
     * @param array<string,scalar|null> $params Route params
     * @param array<string,scalar|array|null> $query Query params
     * @param bool $absolute Whether to include base URI
     * @return string
     */
    public function route(
        string $name,
        array $params = [],
        array $query = [],
        bool $absolute = false,
    ): string {
        return $this->urlGenerator->urlFor($name, $params, $query, $absolute);
    }
}
