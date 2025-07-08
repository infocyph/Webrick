<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Infocyph\Webrick\Router\Route\Collection;
use InvalidArgumentException;

class UrlGenerator
{
    private string $baseUri;
    private Collection $routes;

    public function __construct(string $baseUri, Collection $routes)
    {
        // Strip any trailing slash so we can always do "$baseUri . '/' . ltrim(path)"
        $this->baseUri = rtrim($baseUri, '/');
        $this->routes = $routes;
    }

    /* -----------------------------------------------------------------
     *  Public API
     * ----------------------------------------------------------------*/

    /**
     * Build a URL for a **named route**.
     *
     * @param non-empty-string $name
     * @param array<string,mixed> $params Placeholder values
     * @param array<string,mixed> $query Query parameters
     * @param bool $absolute Prepend baseUri?
     */
    public function urlFor(
        string $name,
        array $params = [],
        array $query = [],
        bool $absolute = true,
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
     * @param non-empty-string $path Leading slash optional
     * @param array<string,scalar|array|null> $query
     * @param bool $absolute
     * @return string
     */
    public function to(
        string $path,
        array $query = [],
        bool $absolute = true,
    ): string {
        return $this->build($path, $query, $absolute);
    }

    /**
     * Build a URL by **handler reference**.
     *
     * @param callable|string $handler Class::method or callable name
     * @param array<string,mixed> $params
     * @param array<string,mixed> $query
     * @param bool $absolute
     */
    public function action(
        callable|string $handler,
        array $params = [],
        array $query = [],
        bool $absolute = true,
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

    /**
     * Replace `{name}` or `{name:type}` in the template with URL-encoded scalars.
     *
     * @param string $template
     * @param array<string,mixed> $params
     * @return string
     * @throws InvalidArgumentException
     */
    private function substitute(string $template, array $params): string
    {
        // Fast path: no placeholders?
        if (!str_contains($template, '{')) {
            return $template;
        }

        $result = (string)preg_replace_callback(
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
                return rawurlencode((string)$val);
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

    /**
     * Join path + query and optionally prepend baseUri.
     *
     * @param string $path
     * @param array<string,mixed> $query
     * @param bool $absolute
     */
    private function build(string $path, array $query, bool $absolute): string
    {
        $prefix = $absolute ? $this->baseUri : '';
        $uri = $prefix . '/' . ltrim($path, '/');

        if ($query === []) {
            return $uri;
        }

        // RFC3986 encoding:
        return $uri . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
