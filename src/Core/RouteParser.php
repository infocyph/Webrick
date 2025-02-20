<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

/**
 * Converts placeholder-based paths (e.g., "/user/{id:\d+}") into regex patterns
 * capturing named parameters with optional constraints.
 */
class RouteParser
{
    public function parse(string $path): array
    {
        // Example: "/user/{id:\d+}"
        // => pattern: /\/user\/(?P<id>\d+)/
        // => paramNames: ['id']
        $regex = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}/',
            function ($matches) {
                $paramName = $matches[1];
                $constraint = $matches[2] ?? '[^/]+';

                return '(?P<' . $paramName . '>' . $constraint . ')';
            },
            preg_quote($path, '/'),
        );

        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(?::[^}]+)?\}/', $path, $paramMatches);
        $paramNames = $paramMatches[1] ?? [];

        $pattern = '/^' . $regex . '$/';

        return [
            'pattern' => $pattern,
            'paramNames' => $paramNames,
        ];
    }
}
