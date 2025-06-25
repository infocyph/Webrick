<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraints;

/** Matches RFC-4122 UUID v1–v5 */
final class UuidConstraint implements ConstraintInterface
{
    public function pattern(): string
    {
        return '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';
    }
}
