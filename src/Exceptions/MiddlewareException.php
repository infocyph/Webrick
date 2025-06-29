<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

/**
 * Thrown when a middleware class fails during registration
 * or execution (e.g. bad signature, unmet dependency, etc.).
 */
final class MiddlewareException extends \RuntimeException
{
    /**
     * @param class-string<object>|string $middleware  The middleware ID / FQCN
     */
    public function __construct(
        public readonly string $middleware,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?: "Middleware failure: {$middleware}",
            $code,
            $previous,
        );
    }
}
