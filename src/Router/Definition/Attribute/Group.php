<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

use Attribute;

/**
 * Class-level shortcut that pre-configures a group for all contained routes.
 *
 * ```php
 * #[Group(prefix: '/admin', middleware: [Auth::class], name: 'admin.')]
 * class AdminController { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Group
{
    /**
     * Configure a route group for all routes defined on the class.
     *
     * Behaviour:
     *  - $prefix is prepended to each route path in the class.
     *  - $domain, when provided, constrains routes to a specific host.
     *  - $middleware is a list of middleware class-strings or instantiated objects
     *    that should be applied to all routes in the group (order preserved).
     *  - $name is a prefix applied to route names declared within the class.
     *
     * All values are stored as public readonly properties and intended to be
     * inspected by the routing/registration layer. This attribute does not
     * itself perform any validation beyond PHP typing.
     *
     * @param string $prefix Path prefix to apply to all routes (default: '')
     * @param string|null $domain Optional domain constraint for the group
     * @param list<class-string|object> $middleware Middleware to apply to contained routes
     * @param string $name Optional route name prefix (default: '')
     */
    public function __construct(
        public string $prefix = '',
        public ?string $domain = null,
        /** @var list<class-string|object> */
        public array $middleware = [],
        public string $name = '',
    ) {
    }
}
