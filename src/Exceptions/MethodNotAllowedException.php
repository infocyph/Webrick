<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

final class MethodNotAllowedException extends \RuntimeException
{
    /**
     * Initializes a new instance of the MethodNotAllowedException class.
     *
     * @param string $verb The HTTP verb that was used.
     * @param string $path The path that was requested.
     * @param array $allowed The list of allowed HTTP verbs for the given path.
     * @param int $code The error code.
     * @param \Throwable|null $previous The previous throwable used for the exception chaining.
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
     * Return the list of allowed methods for the given path.
     *
     * @return array<string> List of allowed HTTP methods.
     */
    public function allowed(): array
    {
        return $this->allowed;
    }
}
