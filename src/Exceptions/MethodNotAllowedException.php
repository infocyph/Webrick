<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

/**
 * Thrown when the requested HTTP verb is not permitted for a matched path.
 *
 * @psalm-type NonEmptyString = non-empty-string
 */
final class MethodNotAllowedException extends \RuntimeException
{
    /**
     * @param NonEmptyString $verb e.g. "POST"
     * @param NonEmptyString $path e.g. "/users/42"
     * @param list<NonEmptyString> $allowed e.g. ["GET", "HEAD"]
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

    /** Return the list of allowed HTTP verbs for this path. */
    public function allowed(): array
    {
        return $this->allowed;
    }
}
