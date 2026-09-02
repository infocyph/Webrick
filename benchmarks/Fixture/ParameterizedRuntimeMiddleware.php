<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks\Fixture;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class ParameterizedRuntimeMiddleware
{
    public function __construct(
        public string $limit,
        public string $window,
    ) {}

    public function __invoke(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
