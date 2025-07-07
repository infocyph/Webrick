<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Support;

use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Route\{Route, CompiledRoute};

/**
 * Turns a *declarative* {@see Route} into a hot-path {@see CompiledRoute}.
 *
 * Responsibilities
 * ----------------
 *  • Detect whether the URI is **dynamic** (contains `{…}` placeholders)
 *  • Build an efficient PCRE pattern and ordered variable list
 *  • Copy across meta-data (domain, middleware, name, handler) unchanged
 */
final class Compiler
{
    private const string DEFAULT_SEGMENT_REGEX = '[^/]+';

    /**
     * Compile a single route.
     */
    public static function compile(Route $route, ConstraintRegistry $constraints): CompiledRoute
    {
        [$regex, $vars, $isDynamic] = self::buildRegex(
            $route->getPath(),
            $constraints
        );

        return new CompiledRoute(
            method     : $route->getMethod(),
            path       : $route->getPath(),
            handler    : $route->getHandler(),
            domain     : $route->getDomain(),
            middleware : $route->getMiddlewares(),
            name       : $route->getName(),
            dynamic    : $isDynamic,
            regex      : $regex,
            variables  : $vars,
        );
    }

    /**
     * Convert `{id:int}` style placeholders into a full PCRE pattern.
     *
     * @return array{0:string,1:list<non-empty-string>,2:bool}
     *   [0] compiled regex, [1] variable names, [2] dynamic flag
     */
    private static function buildRegex(string $path, ConstraintRegistry $constraints): array
    {
        // Fast path – completely static URI
        if (str_contains($path, '{') === false) {
            return ['#^' . preg_quote($path, '#') . '$#D', [], false];
        }

        $segments   = explode('/', Utils::trimSlashes($path));
        $variables  = [];
        $patternBuf = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                // Leading / trailing slash, already handled by trimSlashes()
                continue;
            }

            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}$/', $segment, $m)) {
                // Placeholder
                $varName   = $m[1];
                $constraintKey = $m[2] ?? null;

                $regexPart = $constraintKey
                    ? $constraints->buildPattern($constraintKey)
                    : self::DEFAULT_SEGMENT_REGEX;

                $patternBuf[] = '(' . $regexPart . ')';
                $variables[]  = $varName;
                continue;
            }

            // Literal segment
            $patternBuf[] = preg_quote($segment, '#');
        }

        $regex = '#^/' . implode('/', $patternBuf) . '$#D';

        return [$regex, $variables, true];
    }
}
