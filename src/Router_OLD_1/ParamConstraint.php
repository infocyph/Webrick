<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use RuntimeException;

/**
 * Central registry for path-parameter constraints.
 *
 *  • Each type is represented by a PCRE fragment (used by the compiler)
 *    **and** can be validated at runtime via {@see validate()}.
 *  • Built-ins live in {@see DEFAULTS}; no per-type inline closures.
 *  • Custom rules may be added at runtime with {@see register()}.
 *
 * Why *both* regex + PHP validation?
 * ----------------------------------
 *  1. **Route-match time** – we just embed the regex fragment; this is
 *     the fastest way to reject impossible paths.
 *  2. **URL-generation / runtime assertions** – when developers call
 *     `$router->urlFor('user.show', ['id' => $foo])`, we need a *cheap*
 *     PHP-side guard to throw early if `$foo` is invalid, _without_
 *     recompiling regexes.  That’s what {@see validate()} is for.
 */
final class ParamConstraint
{
    /** built-ins (may be overridden) */
    private const DEFAULTS = [
        'int'  => '[0-9]+',
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}',
        'slug' => '[a-z0-9\\-]+',
        'any'  => '[^/]+',
    ];

    /** @var array<string,string>  map<type,regex> */
    private static array $map = self::DEFAULTS;

    /* ------------------------------------------------------------------
       1.  Registration
       -----------------------------------------------------------------*/
    public static function register(string $name, string $regex): void
    {
        if ($name === '') {
            throw new RuntimeException('Constraint name must be non-empty');
        }
        self::$map[$name] = trim($regex, '/');
    }

    public static function has(string $name): bool
    {
        return isset(self::$map[$name]);
    }

    public static function regex(string $name): string
    {
        return self::$map[$name] ?? self::$map['any'];
    }

    /* ------------------------------------------------------------------
       2.  Runtime validation (single match-expression)
       -----------------------------------------------------------------*/
    public static function validate(string $type, string $value): bool
    {
        return match ($type) {
            'int'  => $value !== '' && ctype_digit($value),

            'uuid', 'slug', 'any' => (bool) preg_match(
                '/^' . self::regex($type) . '$/u',
                $value
            ),

            /* custom types ------------------------------------------ */
            default => self::has($type)
                && (bool) preg_match('/^' . self::regex($type) . '$/u', $value),
        };
    }

    /** bc-alias used by other components */
    public static function check(string $type, string $value): bool
    {
        return self::validate($type, $value);
    }
}
