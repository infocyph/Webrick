<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * ```php
 * #[Route('GET', '/users', name: 'users.index', middleware: ['auth'])]
 * public function index() { … }
 * ```
 *
 *  • `$method` accepts string | string[] (verbs in upper-case).
 *  • The attribute is *repeatable* to allow multiple verbs on one target.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param string|string[]     $method
     * @param array<string>|null  $middleware
     */
    public function __construct(
        public string|array $method,
        public string       $path,
        public ?string      $name       = null,
        public array        $middleware = [],
        public ?string      $domain     = null,
    ) {}
}
