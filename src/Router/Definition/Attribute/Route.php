<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

use Attribute;

/**
 * Declare a route directly on a controller method.
 *
 * ```php
 * #[Route(method: ['GET','POST'], path: '/login', name: 'auth.login')]
 * public function login() { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /** @param string[] $method */
    public array $method;

    /** @param string|string[] $method */
    public function __construct(
        array|string $method,
        public string $path,
        public ?string $name = null,
        /** @var list<class-string|object> */
        public array $middleware = [],
    ) {
        $this->method = (array)$method;  // normalise early
    }
}
