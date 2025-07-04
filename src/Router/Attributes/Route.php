<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Marks a controller class or method as an HTTP route.
 *
 * Example:
 * ```php
 * #[Route('GET', '/users', name: 'users.index', middleware: ['auth'])]
 * public function index() {}
 * ```
 *
 *  • `$method` accepts string | string[] (verbs in upper-case).
 *  • Repeatable to bind multiple verbs to the same target.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param string|string[] $method
     * @param list<string>    $middleware
     */
    public function __construct(
        public string|array $method,
        public string       $path,
        public ?string      $name        = null,
        public array        $middleware  = [],
        public ?string      $domain      = null,
    ) {}
}
