<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

enum ExecutionKind: string
{
    case DIRECT_ZERO_ARG = 'direct_zero_arg';
    case DIRECT_ROUTE_ARGS = 'direct_route_args';
    case DIRECT_REQUEST = 'direct_request';
    case COMPILED_INVOKE = 'compiled_invoke';
    case MIDDLEWARE_PIPELINE = 'middleware_pipeline';
}
