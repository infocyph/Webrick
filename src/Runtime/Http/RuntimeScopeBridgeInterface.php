<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Runtime\InterMixRuntime;

/**
 * Optional runtime-specific request-scope bridge.
 *
 * Core dispatch knows only that a runtime may already own the logical DI
 * boundary. Concrete adapters decide how that scope is attached.
 */
interface RuntimeScopeBridgeInterface
{
    public function withinScope(
        InterMixRuntime $runtime,
        ?Request $request,
        callable $callback,
    ): mixed;
}
