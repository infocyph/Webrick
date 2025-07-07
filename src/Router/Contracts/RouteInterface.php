<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Contracts;

use Infocyph\Webrick\Interfaces\RouteInterface as CoreRouteInterface;

/**
 * Immutable DTO contract for a single route definition.
 * Re-exported for cohesion with the Router namespace.
 */
interface RouteInterface extends CoreRouteInterface
{
    /* no additional members */
}
