<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Compile;

use Infocyph\Webrick\Router\Runtime\ParamConstraint;

/**
 * Turns patterns like `/user/{id:int}` into an optimised
 * descriptor used by {@see Runtime\RouteCollection}.
 *
 * Returns one of:
 *  • ['kind'=>'static',  'path'=> '/fixed']
 *  • ['kind'=>'dynamic', 'regex'=>'#^/foo/(\d+)$#D', 'vars'=>['id']]
 */
final class RouteCompiler
{
    /**
     * @return array{kind:'static',path:string}|array{kind:'dynamic',regex:string,vars:list<string>}
     */
    public static function compile(string $pattern): array
    {
        if (!str_contains($pattern, '{')) {          // fast-path
            return ['kind' => 'static', 'path' => $pattern];
        }

        $vars = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][\w\-]*)(?::([a-zA-Z_][\w\-]*))?\}/',
            static function (array $m) use (&$vars): string {
                $vars[] = $m[1];                     // capture name
                $type = $m[2] ?? 'any';
                return '(' . ParamConstraint::regex($type) . ')';
            },
            $pattern,
            -1,
            $count
        );

        if ($count === 0) {                          // safety fallback
            return ['kind' => 'static', 'path' => $pattern];
        }

        return [
            'kind' => 'dynamic',
            'regex' => '#^' . $regex . '$#D',
            'vars' => $vars,
        ];
    }
}
