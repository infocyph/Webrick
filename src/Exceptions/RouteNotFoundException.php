<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

final class RouteNotFoundException extends \RuntimeException
{
    /**
     * @param non-empty-string $verb
     * @param non-empty-string $path
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
