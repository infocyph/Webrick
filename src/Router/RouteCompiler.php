<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Router\ParamConstraint;

/**
 * Turns `{id:int}` etc. into optimised regex & var-list.
 *
 * Returns:
 *   • ['kind' => 'static',  'path' => '/fixed']
 *   • ['kind' => 'dynamic','regex' => '#^/foo/(\d+)$#D','vars'=>['id']]
 */
final class RouteCompiler
{
    public static function compile(string $pattern): array
    {
        if (!str_contains($pattern, '{')) {
            return ['kind' => 'static', 'path' => $pattern];
        }

        $varNames = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][\w\-]*)(?::([a-zA-Z_][\w\-]*))?\}/',
            static function (array $m) use (&$varNames): string {
                $varNames[] = $m[1];
                $type = $m[2] ?? 'any';
                return '(' . ParamConstraint::regex($type) . ')';
            },
            $pattern
        );

        return [
            'kind'  => 'dynamic',
            'regex' => '#^' . $regex . '$#D',
            'vars'  => $varNames,
        ];
    }
}
