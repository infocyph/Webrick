<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use RuntimeException;

/**
 * Reverse-router that turns a **named** route into an URI.
 */
final class UrlGenerator
{
    public function __construct(private Runtime\RouteCollection $routes) {}

    /**
     * @param array<string,int|float|string> $params
     */
    public function urlFor(string $name, array $params = [], bool $absolute = false): string
    {
        $route = $this->routes->named($name);
        $tmpl  = $route->getPath();

        $uri = preg_replace_callback(
            '/\{([a-zA-Z_][\w\-]*)(?::[a-zA-Z_][\w\-]*)?\}/',
            static function (array $m) use (&$params, $name): string {
                $key = $m[1];
                if (!array_key_exists($key, $params)) {
                    throw new RuntimeException("Missing param '{$key}' for route '{$name}'");
                }
                return rawurlencode((string) $params[$key]);
            },
            $tmpl
        );

        if ($absolute) {
            $scheme = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return "{$scheme}://{$host}{$uri}";
        }
        return $uri;
    }
}
