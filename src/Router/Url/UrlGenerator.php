<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Infocyph\Webrick\Router\Route\Collection;
use InvalidArgumentException;

/**
 * Generates URLs for named routes, controller actions, and arbitrary paths.
 *
 * This class provides a fluent interface for generating URLs with support for:
 * - Named routes with parameters
 * - Controller/action references
 * - Arbitrary paths with query parameters
 * - Relative and absolute URL generation
 */
class UrlGenerator
{
    /**
     * The base URI to prepend for absolute URLs.
     */
    private readonly string $baseUri;

    /**
     * Initializes the URL generator with base URI and route collection.
     *
     * @param string $baseUri Base URI (typically empty string as domains are handled elsewhere)
     * @param Collection $routes Collection of registered routes
     */
    public function __construct(string $baseUri, private readonly Collection $routes)
    {
        // Strip any trailing slash so we can always do "$baseUri . '/' . ltrim(path)"
        $this->baseUri = rtrim($baseUri, '/');
    }

    /**
     * Build a URL by handler reference.
     *
     * @param callable|string $handler Callable or "Class::method" string
     * @param array<string,scalar|null> $params Route parameters
     * @param array<string,scalar|array|null> $query Query string parameters
     * @param bool $absolute Whether to generate an absolute URL
     * @return string Generated URL
     *
     * @throws InvalidArgumentException If no route is found for the handler
     */
    public function action(
        callable|string $handler,
        array $params = [],
        array $query = [],
        bool $absolute = false,
    ): string {
        $route = $this->routes->findByHandler($handler);
        if ($route === null) {
            throw new InvalidArgumentException('Route for given handler not found.');
        }

        $path = $this->substitute($route->getPath(), $params);

        return $this->build($path, $query, $absolute);
    }

    /**
     * Build a URL to an arbitrary path.
     *
     * @param non-empty-string $path URL path (leading slash optional)
     * @param array<string,scalar|array|null> $query Query string parameters
     * @param bool $absolute Whether to generate an absolute URL
     * @return string Generated URL
     */
    public function to(
        string $path,
        array $query = [],
        bool $absolute = false,
    ): string {
        return $this->build($path, $query, $absolute);
    }

    /* -----------------------------------------------------------------
     *  Public API  (defaults to RELATIVE)
     * ----------------------------------------------------------------*/

    /**
     * Build a URL for a named route.
     *
     * @param non-empty-string $name Name of the route
     * @param array<string,scalar|null> $params Route parameter values
     * @param array<string,scalar|array|null> $query Query string parameters
     * @param bool $absolute Whether to generate an absolute URL
     * @return string Generated URL
     *
     * @throws InvalidArgumentException If the named route is not found
     */
    public function urlFor(
        string $name,
        array $params = [],
        array $query = [],
        bool $absolute = false,
    ): string {
        $route = $this->routes->findByName($name);
        if ($route === null) {
            throw new InvalidArgumentException("Route '{$name}' not found.");
        }

        $path = $this->substitute($route->getPath(), $params);

        return $this->build($path, $query, $absolute);
    }

    /**
     * Constructs the final URL from path, query parameters, and base URI.
     *
     * @param string $path URL path
     * @param array<string,scalar|array|null> $query Query parameters
     * @param bool $absolute Whether to include the base URI
     * @return string The fully constructed URL
     */
    private function build(string $path, array $query, bool $absolute): string
    {
        // If the path exists verbatim in the route table, trust it.
        if ($this->routes->hasPath($path)) {
            $uri = $absolute
                ? $this->baseUri . $path
                : $path;
        } else {
            $uri = ($absolute ? $this->baseUri : '') . '/' . ltrim($path, '/');
        }

        if ($query === []) {
            return $uri;
        }

        // RFC3986 encoding:
        return $uri . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------*/

    /**
     * Replaces placeholders in the URL template with encoded parameter values.
     *
     * @param string $template URL template with {param} or {param:type} placeholders
     * @param array<string,scalar|null> $params Parameter values
     * @return string URL with placeholders replaced
     *
     * @throws InvalidArgumentException If parameters are missing or invalid
     */
    private function substitute(string $template, array $params): string
    {
        // Fast path: no placeholders?
        if (!str_contains($template, '{')) {
            return $template;
        }

        $result = (string) preg_replace_callback(
            '/\{([A-Za-z_]\w*)(?::[^}]+)?}/',
            function (array $m) use ($template, $params): string {
                $key = $m[1];

                if (!array_key_exists($key, $params)) {
                    throw new InvalidArgumentException(
                        "Missing parameter '{$key}' for URL template '{$template}'.",
                    );
                }

                $val = $params[$key];
                if (!is_scalar($val) && $val !== null) {
                    throw new InvalidArgumentException(
                        "Parameter '{$key}' must be scalar or null; got " . gettype($val),
                    );
                }

                // Treat null as empty string
                return rawurlencode((string) $val);
            },
            $template,
        );

        // If anything like "{foo}" remains, we weren't given a param
        if (preg_match('/\{[A-Za-z_]\w*(?::[^}]+)?}/', $result)) {
            throw new InvalidArgumentException(
                "Unable to resolve all placeholders in '{$template}'.",
            );
        }

        return $result;
    }
}
