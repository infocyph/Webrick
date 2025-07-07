<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Contracts;

/**
 * Runtime validator for typed URI placeholders (e.g. `{id:int}`).
 */
interface ConstraintInterface
{
    /**
     * Determine if the raw path segment satisfies the constraint.
     *
     * @param non-empty-string $segment
     */
    public function matches(string $segment): bool;
}
