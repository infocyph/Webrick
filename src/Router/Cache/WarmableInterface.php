<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Cache;

/**
 * Anything that can pre-fill its own cache (routes, constraints, etc.).
 */
interface WarmableInterface
{
    /**
     * Perform the warm-up.
     * May throw on failure; caller decides what to do.
     */
    public function warm(): void;
}
