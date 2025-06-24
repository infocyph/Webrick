<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraints;

/**
 * A route-parameter constraint converts itself to a PCRE fragment.
 *
 * Implementations MUST return *un-anchored* patterns (no `^` / `$`)
 * because they are embedded inside a larger regex.
 */
interface ConstraintInterface
{
    /**
     * Returns the raw regex fragment (without delimiters).
     *
     * Example return values:
     *   '[-a-z0-9]+'        slug
     *   '[0-9]{1,20}'       int
     *   '[0-9a-fA-F]{8}-...' uuid
     */
    public function pattern(): string;
}
