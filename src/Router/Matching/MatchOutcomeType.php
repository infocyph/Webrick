<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

/**
 * Explicit router match states. Normal routing decisions are values, not
 * exceptions or synthetic application routes.
 */
enum MatchOutcomeType: string
{
    case AUTO_OPTIONS = 'auto_options';
    case FOUND = 'found';
    case METHOD_NOT_ALLOWED = 'method_not_allowed';
    case NOT_FOUND = 'not_found';
}
