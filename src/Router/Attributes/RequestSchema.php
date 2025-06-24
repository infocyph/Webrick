<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Declares the DTO that represents the JSON request body.
 *
 * The middleware layer will:
 *   • Validate incoming JSON against the DTO (via reflection).
 *   • Feed the schema into OpenAPI generation.
 *
 * Example:
 *     #[RequestSchema(CreateUserDto::class)]
 *
 * @param class-string $class  Fully-qualified DTO class name
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RequestSchema
{
    public function __construct(public readonly string $class) {}
}
