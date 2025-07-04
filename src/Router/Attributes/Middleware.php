<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Shorthand to attach middleware to a controller or action.
 *
 * ```php
 * #[Middleware('auth')]             // single alias
 * #[Middleware(['auth','throttle'])] // many
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Middleware
{
    /**
     * @param string|string[] $alias  middleware alias(es)
     */
    public function __construct(public string|array $alias) {}
}
