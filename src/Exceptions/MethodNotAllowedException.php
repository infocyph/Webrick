<?php

/**
 * Webrick - HTTP routing exception: Method Not Allowed.
 *
 * Thrown when an HTTP request uses a verb that is not permitted for the
 * requested path. Carries the attempted verb, the requested path, and the
 * list of allowed verbs for convenient handling (e.g., generating 405 responses
 * and Allow headers).
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

use Infocyph\Webrick\Constants\StatusEnum;

/**
 * Exception indicating that the HTTP method is not allowed for the given path.
 */
final class MethodNotAllowedException extends HttpException
{
    /**
     * Create a new MethodNotAllowedException.
     *
     * @param string $verb The attempted HTTP verb (e.g., "POST").
     * @param string $path The requested path (e.g., "/users/1").
     * @param array<int,string> $allowed List of allowed HTTP verbs for the path.
     * @param \Throwable|null $previous Optional previous throwable for chaining.
     */
    public function __construct(
        public readonly string $verb,
        public readonly string $path,
        public readonly array $allowed,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            statusCode: StatusEnum::METHOD_NOT_ALLOWED->value,
            message: "Method {$verb} not allowed for {$path}. Allowed: " . implode(', ', $allowed),
            previous: $previous,
            headers: ['Allow' => implode(', ', $allowed)],
        );
    }

    /**
     * Get the list of allowed HTTP methods for the path.
     *
     * @return array<int,string> Allowed HTTP methods.
     */
    public function allowed(): array
    {
        return $this->allowed;
    }
}
