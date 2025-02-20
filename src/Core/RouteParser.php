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
        // Split on placeholders
        $split = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*(?::[^}]+)?\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        // $split is an array of alternating literal segments and placeholder segments

        $regex = '';
        $paramNames = [];

        foreach ($split as $segment) {
            // If it's a placeholder
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}$/', $segment, $matches)) {
                $paramName  = $matches[1];
                $constraint = $matches[2] ?? '[^/]+';
                $regex     .= '(?P<' . $paramName . '>' . $constraint . ')';
                $paramNames[] = $paramName;
            } else {
                // It's a literal part of the path => escape it
                $regex .= preg_quote($segment, '/');
            }
        }

        $pattern = '/^' . $regex . '$/';
        return [
            'pattern'    => $pattern,
            'paramNames' => $paramNames,
        ];
    }

}
