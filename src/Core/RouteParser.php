<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

/**
 * Converts placeholder-based paths (like "/user/{id}") into
 * regex patterns and captures named parameters.
 */
class RouteParser
{
    /**
     * Returns an array:
     * [
     *   'pattern'   => string, // e.g. /^\/user\/(?P<id>[^/]+)$/
     *   'paramNames'=> string[], // e.g. ['id']
     * ]
     */
    public function parse(string $path): array
    {
        // Convert {param} placeholders to named capture groups
        $pattern = preg_replace_callback(
            '/\{([^}]+)\}/',
            fn ($matches) => '(?P<'.$matches[1].'>[^/]+)',
            preg_quote($path, '/')
        );

        // Extract the parameter names from the original braces
        preg_match_all('/\{([^}]+)\}/', $path, $paramNames);
        $paramNames = $paramNames[1] ?? [];

        $regex = '/^'.$pattern.'$/';

        return [
            'pattern' => $regex,
            'paramNames' => $paramNames,
        ];
    }
}
