<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use Infocyph\Webrick\Response\Response;

/** Boot-selected decision point evaluated before matching and request materialization. */
interface PreRoutingGateInterface
{
    public function evaluate(RoutingInput $routing): ?Response;
}
