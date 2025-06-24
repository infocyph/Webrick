<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Router\Constraints\ConstraintRegistry;

/**
 * Converts `/user/{id:int}` etc. into a compiled PCRE pattern.
 */
final class RouteParser
{
    /**
     * @return array{pattern:string,paramNames:array<int,string>}
     */
    public function parse(string $path): array
    {
        $regex      = '';
        $paramNames = [];

        $parts = preg_split('/(\{[^}]+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $seg) {
            if ($seg === '')          { continue; }

            if ($seg[0] === '{') { // placeholder
                if (!preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}$/', $seg, $m)) {
                    throw new \RuntimeException("Malformed placeholder in path '{$path}'");
                }
                [$ , $name, $type] = $m + [null, null, null];
                $pattern = $type
                    ? (ConstraintRegistry::has($type)
                        ? ConstraintRegistry::get($type)->pattern()
                        : $type                          // raw regex
                    )
                    : '[^/]+';

                $regex      .= '(?P<' . $name . '>' . $pattern . ')';
                $paramNames[] = $name;
            } else {
                $regex .= preg_quote($seg, '/');
            }
        }

        return [
            'pattern'    => '#^' . $regex . '$#u',
            'paramNames' => $paramNames,
        ];
    }
}
