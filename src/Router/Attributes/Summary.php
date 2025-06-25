<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Short, human-readable one-line summary for a route.
 *
 * Example:
 *     #[Summary('Create a new user')]
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Summary
{
    public function __construct(public readonly string $text)
    {
    }
}
