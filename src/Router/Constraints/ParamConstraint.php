<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraints;

/**
 * Static, allocation-free parameter validators.
 * Add new rules by adding public static methods.
 */
final class ParamConstraint
{
    public static function int(string $v): bool
    {
        return $v !== '' && ctype_digit($v);
    }

    public static function uuid(string $v): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
            $v
        );
    }
}
