<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

use Attribute;

/**
 * Attach middleware to a controller **class** or **method**.
 *
 * ```php
 * #[Middleware(Auth::class)]
 * class AdminController { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Middleware
{
    /** @param class-string|object ...$stack */
    public function __construct(public array $stack)
    {
    }
}
