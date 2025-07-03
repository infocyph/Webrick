<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use RuntimeException;

/**
 * Central registry for *path-parameter* constraints.
 *
 * ──────────────────────────────────────────────────────────────
 *  • Each type has (a) a regex fragment   and   (b) a PHP guard.
 *  • Built-ins live in {@see DEFAULTS}; users may {@see register()} extras.
 *  • Zero allocations or lambdas on the hot path.
 * ──────────────────────────────────────────────────────────────
 */
final class ParamConstraint
{
    /** @var array<string,string>  map<alias,regex-fragment> */
    private const array DEFAULTS = [
        'int'  => '[0-9]+',
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}',
        'slug' => '[a-z0-9\\-]+',
        'any'  => '[^/]+',
    ];

    /** @var array<string,string> dynamically-extendable map */
    private static array $map = self::DEFAULTS;

    /* ──────────────────────────────────────────────────────────
       1. Registration API (boot-time only)
       ───────────────────────────────────────────────────────── */
    public static function register(string $alias, string $regex): void
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new RuntimeException('Constraint alias must be non-empty');
        }
        self::$map[$alias] = trim($regex, '/');
    }

    public static function has(string $alias): bool
    {
        return isset(self::$map[$alias]);
    }

    /**
     * Return PCRE fragment for compiler.
     * Falls back to <any> if alias unknown.
     */
    public static function regex(string $alias): string
    {
        return self::$map[$alias] ?? self::$map['any'];
    }

    /* ──────────────────────────────────────────────────────────
       2. Runtime validation
       ───────────────────────────────────────────────────────── */
    public static function validate(string $alias, string $value): bool
    {
        return match ($alias) {
            'int'  => $value !== '' && ctype_digit($value),

            /* canonical UUID v1–5 */
            'uuid' => (bool) preg_match(
                '/^' . self::regex('uuid') . '$/Di',
                $value
            ),

            /* ‘slug’ and ‘any’ and any custom alias */
            default => self::has($alias)
                && (bool) preg_match('/^' . self::regex($alias) . '$/u', $value),
        };
    }

    /* BC alias used by older code-paths */
    public static function check(string $alias, string $value): bool
    {
        return self::validate($alias, $value);
    }

    /**
     * Prevent instantiation.
     * @codeCoverageIgnore
     */
    private function __construct() {}
}
