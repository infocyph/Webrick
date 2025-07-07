<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Contracts;

use Infocyph\Webrick\Interfaces\RouterInterface as CoreRouterInterface;

/**
 * Local alias that re-exports the framework’s core router contract.
 *
 * Keeping the interface here lets packages type-hint
 * `Router\Contracts\RouterInterface` without importing the deeper path.
 */
interface RouterInterface extends CoreRouterInterface
{
    /* no additional members */
}
