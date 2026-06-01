<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router as Route;

if (!\function_exists('route')) {
    /**
     * Generate a URL for a named route.
     *
     * @param string $name Route name
     * @param array<string,scalar|null> $params Route parameters
     * @param array<string,mixed> $query Query string parameters
     * @param bool $absolute Whether to generate absolute URL (domain-aware when available)
     */
    function route(
        string $name,
        array $params = [],
        array $query = [],
        bool $absolute = false,
    ): string {
        return Route::urlFor($name, $params, $query, $absolute);
    }
}
