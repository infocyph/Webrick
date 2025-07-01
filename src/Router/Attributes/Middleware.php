<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Shorthand to stick middleware on a controller or action.
 *
 * ```php
 * #[Middleware('auth')]
 * class AdminController { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Middleware
{
    /**
     * @param string|string[] $alias   One or many middleware aliases.
     */
    public function __construct(public string|array $alias) {}
}
