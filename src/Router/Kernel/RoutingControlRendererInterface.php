<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Matching\MatchOutcome;
use Infocyph\Webrick\Router\Runtime\RoutingInput;

/** Explicit rendering contract for ordinary router control outcomes such as 404/405. */
interface RoutingControlRendererInterface
{
    public function render(RoutingInput $routing, MatchOutcome $outcome): Response;
}
