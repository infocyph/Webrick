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
    /**
     * Construct a middleware attribute instance.
     *
     * Behaviour:
     *  - Accepts a list of middleware entries which may be either class-strings
     *    (e.g. MyMiddleware::class) or instantiated middleware objects.
     *  - The provided stack is stored on the public readonly property $stack and
     *    is intended to be read by the routing/dispatch layer when assembling
     *    middleware for a route or controller.
     *
     * Notes:
     *  - The attribute is repeatable; multiple Middleware attributes on the same
     *    target will be processed in declaration order.
     *
     * @param list<class-string|object> $stack Middleware entries (class-string or object)
     */
    public function __construct(public array $stack) {}
}
