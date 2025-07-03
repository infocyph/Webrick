<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use RuntimeException;

/**
 * Tiny helper that reverses a named route back into a URI.
 *
 *  • Replaces placeholders with provided $params.
 *  • Throws early if required params are missing.
 *  • Optionally prefixes the current scheme/host when $absolute = true.
 */
final class UrlGenerator
{
    public function __construct(private RouteCollection $routes) {}

    /**
     * @param array<string,int|float|string> $params
     */
    public function urlFor(string $name, array $params = [], bool $absolute = false): string
    {
        $route = $this->routes->named($name);
        $path  = $route->getPath();

        // Rewrite placeholders
        $uri = preg_replace_callback(
            '/\{([a-zA-Z_][\w\-]*)(?::[a-zA-Z_][\w\-]*)?\}/',
            static function ($m) use (&$params, $name): string {
                $key = $m[1];
                if (!array_key_exists($key, $params)) {
                    throw new RuntimeException("Missing param '{$key}' for route '{$name}'");
                }
                return rawurlencode((string) $params[$key]);
            },
            $path
        );

        if ($absolute) {
            $scheme = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return $scheme . '://' . $host . $uri;
        }
        return $uri;
    }
}
