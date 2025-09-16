<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

final class RouteNotFoundException extends \RuntimeException
{
    /**
     * Constructor.
     *
     * @param string $verb The HTTP verb associated with the route.
     * @param string $path The path associated with the route.
     * @param int $code The HTTP status code associated with the exception.
     * @param \Throwable|null $previous The previous exception, if any.
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
