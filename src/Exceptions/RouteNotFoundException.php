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

use Infocyph\Webrick\Constants\StatusEnum;

/**
 * Exception indicating that no route exists for the given HTTP verb and path.
 */
final class RouteNotFoundException extends HttpException
{
    /**
     * Create a new RouteNotFoundException.
     *
     * @param string $verb The HTTP verb associated with the request (e.g., "GET").
     * @param string $path The requested path (e.g., "/users/1").
     * @param \Throwable|null $previous Optional previous throwable for chaining.
     */
    public function __construct(
        public readonly string $verb,
        public readonly string $path,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(StatusEnum::NOT_FOUND->value, "No route defined for {$verb} {$path}", previous: $previous);
    }
}
