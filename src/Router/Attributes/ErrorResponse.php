<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Adds an explicit error response (non-2xx) with its DTO schema.
 * Still optional—generic StandardError responses are auto-added.
 *
 * Example:
 *     #[ErrorResponse(404, NotFoundDto::class)]
 *
 * @param int           $code  HTTP status code
 * @param class-string  $dto   DTO describing the JSON error body
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ErrorResponse
{
    public function __construct(
        public readonly int    $code,
        public readonly string $dto,
    ) {
    }
}
