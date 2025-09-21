<?php

/**
 * Webrick - HTTP routing exception: Method Not Allowed.
 *
 * Thrown when an HTTP request uses a verb that is not permitted for the
 * requested path. Carries the attempted verb, the requested path, and the
 * list of allowed verbs for convenient handling (e.g., generating 405 responses
 * and Allow headers).
 *
 * @package Infocyph\Webrick\Exceptions
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

/**
 * Exception indicating that the HTTP method is not allowed for the given path.
 */
final class MethodNotAllowedException extends \RuntimeException
{
    /**
     * Create a new MethodNotAllowedException.
     *
     * @param string                 $verb     The attempted HTTP verb (e.g., "POST").
     * @param string                 $path     The requested path (e.g., "/users/1").
     * @param array<int,string>      $allowed  List of allowed HTTP verbs for the path.
     * @param int                    $code     Optional error code.
     * @param \Throwable|null        $previous Optional previous throwable for chaining.
     */
    public function __construct(
        public readonly string $verb,
        public readonly string $path,
        public readonly array $allowed,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            message: "Method {$verb} not allowed for {$path}. Allowed: " . implode(', ', $allowed),
            code: $code,
            previous: $previous,
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