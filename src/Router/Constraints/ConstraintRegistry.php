<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraints;

/**
 * Global registry mapping alias → Constraint instance.
 *
 * Users may add their own constraints at boot time:
 *     ConstraintRegistry::add('alpha', new RegexConstraint('[A-Za-z]+'));
 *      ConstraintRegistry::add('year', new RegexConstraint('[12][0-9]{3}'));
 */
final class ConstraintRegistry
{
    /** @var array<string,ConstraintInterface> */
    private static array $map;

    /** Initialise the built-ins only once */
    private static function init(): void
    {
        if (isset(self::$map)) { return; }
        self::$map = [
            'int'  => new IntConstraint(),
            'uuid' => new UuidConstraint(),
            'slug' => new SlugConstraint(),
            'bool' => new BoolConstraint(),
        ];
    }

    public static function add(string $alias, ConstraintInterface $c): void
    {
        self::init();
        self::$map[$alias] = $c;
    }

    public static function has(string $alias): bool
    {
        self::init();
        return isset(self::$map[$alias]);
    }

    public static function get(string $alias): ConstraintInterface
    {
        self::init();
        if (!isset(self::$map[$alias])) {
            throw new \RuntimeException("Unknown route constraint '{$alias}'");
        }
        return self::$map[$alias];
    }
}
