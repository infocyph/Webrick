<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Compile;

use Infocyph\Webrick\Router\Runtime\ParamConstraint;

/**
 * Static helpers for tools that need to introspect route patterns
 * outside of the hot-path matcher.
 */
final class PatternUtils
{
    /** @return list<string> */
    public static function extractVariables(string $pattern): array
    {
        preg_match_all(
            '/\{([a-zA-Z_][\w\-]*)(?::[a-zA-Z_][\w\-]*)?\}/',
            $pattern,
            $m
        );
        return $m[1] ?? [];
    }

    public static function regexFor(string $type): string
    {
        return ParamConstraint::regex($type);
    }
}
