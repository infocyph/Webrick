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
    public function __construct(
        public string $prefix = '',
        public ?string $domain = null,
        /** @var list<class-string|object> */
        public array $middleware = [],
        public string $name = '',
    ) {}
}
