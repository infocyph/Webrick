<?php

/**
 * Webrick - HTTP routing exception: Route Not Found.
 *
 * Thrown when an HTTP request targets a path/verb combination that does not
 * resolve to any registered route. Carries the attempted verb and requested
 * path to aid error handling and diagnostics.
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

/**
 * Exception indicating that no route exists for the given HTTP verb and path.
 */
final class RouteNotFoundException extends \RuntimeException
{
    /**
     * Create a new RouteNotFoundException.
     *
     * @param string $verb The HTTP verb associated with the request (e.g., "GET").
     * @param string $path The requested path (e.g., "/users/1").
     * @param int $code Optional error code.
     * @param \Throwable|null $previous Optional previous throwable for chaining.
     */
    public function __construct(
        public readonly string $verb,
        public readonly string $path,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            message: "No route defined for $verb $path",
            code: $code,
            previous: $previous,
        );
    }
}
