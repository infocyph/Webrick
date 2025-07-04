<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use RuntimeException;

/**
 * Central registry for path-parameter constraints.
 *
 *  • Provides both a PCRE fragment (compile-time) **and**
 *    an ultra-fast runtime validator (match-expression).
 */
final class ParamConstraint
{
    private const DEFAULTS = [
        'int'  => '[0-9]+',
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}',
        'slug' => '[a-z0-9\\-]+',
        'any'  => '[^/]+',
    ];

    /** @var array<string,string> */
    private static array $map = self::DEFAULTS;

    /* ------------------------------------------------------------------ */
    /* Registration                                                       */
    /* ------------------------------------------------------------------ */
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

    /* ------------------------------------------------------------------ */
    /* Runtime validation (fast match-expression)                         */
    /* ------------------------------------------------------------------ */
    public static function validate(string $type, string $value): bool
    {
        return match ($type) {
            'int'  => $value !== '' && ctype_digit($value),

            'uuid', 'slug', 'any' => (bool) preg_match(
                '/^' . self::regex($type) . '$/u',
                $value
            ),

            default => self::has($type)
                && (bool) preg_match('/^' . self::regex($type) . '$/u', $value),
        };
    }

    /** Back-compat alias */
    public static function check(string $type, string $value): bool
    {
        return self::validate($type, $value);
    }
}
