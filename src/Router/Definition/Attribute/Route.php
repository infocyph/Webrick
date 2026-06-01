<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

use Attribute;

/**
 * Declare a route directly on a controller method or class.
 *
 * Usage:
 *   #[Route(method: ['GET','POST'], path: '/login', name: 'auth.login')]
 *   public function login() { … }
 *
 * This attribute is repeatable and may be applied to methods or classes.
 *
 * Properties:
 *  - public array $method : list of HTTP methods this route responds to
 *  - public string $path  : route path template (may include segment tokens)
 *  - public ?string $name : optional route name used for URL generation
 *  - public array $middleware : list of middleware entries applied to the route
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /**
     * List of HTTP methods this route accepts.
     *
     * Normalised to an array during construction. Each entry is a method name
     * such as "GET", "POST", "PUT", etc. Consumers should treat values as
     * case-insensitive.
     *
     * @var string[]
     */
    public array $method;

    /**
     * Construct a Route attribute instance.
     *
     * Behaviour:
     *  - $method may be a single method string or an array of methods. The
     *    constructor normalises it to an array and preserves the caller order.
     *  - $path is the route path template (e.g. "/users/{id}").
     *  - $name, when provided, is the route's optional name for URL generation.
     *  - $middleware is a list of middleware class-strings or instantiated
     *    middleware objects to be applied to the route (order preserved).
     *
     * @param string|string[] $method HTTP method or list of methods this route responds to
     * @param string $path Route path template
     * @param string|null $name Optional route name (defaults to null)
     * @param list<class-string|object> $middleware Middleware entries to apply to this route
     */
    public function __construct(
        array|string $method,
        public string $path,
        public ?string $name = null,
        /** @var list<class-string|object> */
        public array $middleware = [],
    ) {
        $this->method = (array) $method;  // normalise early
    }
}
