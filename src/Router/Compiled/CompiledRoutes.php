<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Compiled;

use JsonSerializable;

/**
 * A serialisable *snapshot* of all routes – used by
 * `php webrick route:list` and possible static analysis.
 */
final class CompiledRoutes implements JsonSerializable
{
    /** @param list<CompiledRoute> $routes */
    public function __construct(public array $routes) {}

    public function jsonSerialize(): array
    {
        return $this->routes;
    }
}
