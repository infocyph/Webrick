<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Internal;

use Infocyph\Webrick\Router\ParamConstraint;

/**
 * *Shared* helper for tools that need to introspect path patterns
 * outside of runtime matching (e.g. UrlGenerator or route:list).
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

    public static function regexForType(string $type): string
    {
        return ParamConstraint::regex($type);
    }
}
