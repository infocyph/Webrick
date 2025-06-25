<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraints;

/** Matches "true", "false", "1", "0" (case-insensitive) */
final class BoolConstraint implements ConstraintInterface
{
    public function pattern(): string
    {
        return '(?:1|0|true|false)';
    }
}
