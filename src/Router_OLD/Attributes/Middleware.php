<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router_OLD\Attributes;

use Attribute;

/**
 * Attach PSR-15 middleware by class-id or tag:
 *   #[Middleware(AuthMiddleware::class)]
 *   #[Middleware(['auth', 'throttle'])]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class Middleware
{
    /** @param string|string[] $ids */
    public function __construct(public string|array $ids) {}
}
