<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Explicitly sets the success-response DTO (status 200/201).
 * If omitted, the generator will try to infer it from the method
 * return-type or runtime DTO registry.
 *
 * Example:
 *     #[ResponseSchema(UserDto::class)]
 *
 * @param class-string $class  Fully-qualified DTO class name
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ResponseSchema
{
    public function __construct(public readonly string $class) {}
}
