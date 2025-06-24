<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraints;

/** Matches lowercase slugs like "my-blog-post-12" */
final class SlugConstraint implements ConstraintInterface
{
    public function pattern(): string
    {
        return '[a-z0-9]+(?:-[a-z0-9]+)*';
    }
}
