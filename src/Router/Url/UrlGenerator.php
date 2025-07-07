<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Infocyph\Webrick\Router\Route\Collection;
use InvalidArgumentException;

class UrlGenerator
{
    private string $baseUri;     // e.g. "https://api.example.com"
    private Collection $routes;

    public function __construct(string $baseUri, Collection $routes)
    {
        $this->baseUri = rtrim($baseUri, '/');
        $this->routes  = $routes;
    }

    /* -----------------------------------------------------------------
     *  Public API
     * ----------------------------------------------------------------*/

    /**
     * Build a URL for a **named route**.
     *
     * @param non-empty-string      $name
     * @param array<string,mixed>   $params  Placeholder values
     * @param array<string,mixed>   $query   Extra query parameters
     */
    public function urlFor(
        string $name,
        array  $params = [],
        array  $query = [],
        bool   $absolute = true,
    ): string {
        $route = $this->routes->findByName($name);

        if ($route === null) {
            throw new InvalidArgumentException("Route '{$name}' not found.");
        }

        $path = $this->substitute($route->getPath(), $params);

        return $this->build($path, $query, $absolute);
    }

    /**
     * Build a URL to an **arbitrary path**.
     *
     * @param non-empty-string                 $path   "/foo/bar"
     * @param array<string,scalar|array|null>  $query
     */
    public function to(
        string $path,
        array  $query = [],
        bool   $absolute = true,
    ): string {
        return $this->build($path, $query, $absolute);
    }

    /**
     * Build a URL by **handler reference** (class name or callable string).
     *
     * @param callable|string $handler
     * @param array<string,mixed> $params
     * @param array $query
     * @param bool $absolute
     * @return string
     */
    public function action(
        callable|string $handler,
        array $params = [],
        array $query = [],
        bool  $absolute = true,
    ): string {
        $route = $this->routes->findByHandler($handler);

        if ($route === null) {
            throw new InvalidArgumentException('Route for given handler not found.');
        }

        $path = $this->substitute($route->getPath(), $params);

        return $this->build($path, $query, $absolute);
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------*/

    /** Replace `{var}` in path template with URL-encoded values. */
    private function substitute(string $template, array $params): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            function (array $m) use ($template, $params): string {
                $key = $m[1];
                if (!array_key_exists($key, $params)) {
                    throw new InvalidArgumentException(
                        "Missing parameter '{$key}' for URL template '{$template}'."
                    );
                }
                return rawurlencode((string) $params[$key]);
            },
            $template
        );
    }

    /** Join path + query and add base URI when absolute=true. */
    private function build(string $path, array $query, bool $absolute): string
    {
        $uri  = ($absolute ? $this->baseUri : '') . '/' . ltrim($path, '/');
        $qs   = $query ? http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';

        return $qs === '' ? $uri : $uri . '?' . $qs;
    }
}
