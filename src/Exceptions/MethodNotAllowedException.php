<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

final class MethodNotAllowedException extends \RuntimeException
{
    /**
     * @param non-empty-string   $verb     e.g. "POST"
     * @param non-empty-string   $path     e.g. "/users/42"
     * @param non-empty-string[] $allowed  e.g. ["GET", "HEAD"]
     */
    public function __construct(
        public readonly string $verb,
        public readonly string $path,
        public readonly array  $allowed,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            message : "Method {$verb} not allowed for {$path}. Allowed: " .
            implode(', ', $allowed),
            code    : $code,
            previous: $previous,
        );
    }
}
