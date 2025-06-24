<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Adds one or more tags to an operation in the generated OpenAPI spec.
 *
 * Example:
 *     #[Tag('auth')]
 *     #[Tag('admin')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Tag
{
    public function __construct(public readonly string $name)
    {
    }
}
