<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router_OLD;

final class RouteParser
{
    /** @var array<string,string> alias ⇒ validator-method */
    private static array $aliases = [
        'int'  => 'int',
        'uuid' => 'uuid',
    ];

    public static function extend(string $alias, string $method): void
    {
        self::$aliases[$alias] = $method;
    }

    /**
     * @return array{regex:string,paramNames:string[],validators:array<string,string>}
     */
    public function parse(string $path): array
    {
        $pieces      = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*(?::[^}]+)?\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regexParts  = [];
        $names       = [];
        $validators  = [];

        foreach ($pieces as $piece) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}$/', $piece, $m)) {
                [$all, $name, $constraint] = $m + ['', '', ''];
                $names[] = $name;

                $validator = null;
                if ($constraint !== '') {
                    $validator = self::$aliases[$constraint] ?? null;
                    $pattern   = $validator
                        ? '(?P<' . $name . '>[^/]+)'         // regex kept generic, validator refines
                        : '(?P<' . $name . '>' . $constraint . ')';
                } else {
                    $pattern = '(?P<' . $name . '>[^/]+)';
                }

                if ($validator) {
                    $validators[$name] = $validator;
                }
                $regexParts[] = $pattern;
            } else {
                $regexParts[] =  preg_quote($piece, '#');
            }
        }

        return [
            'regex'      => '#^' . implode('', $regexParts) . '$#D',
            'paramNames' => $names,
            'validators' => $validators,
        ];
    }
}
