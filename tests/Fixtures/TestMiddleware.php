<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Fixture;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class TestMiddleware
{
    /**
     * @param Closure(Request):Response $next
     * @throws \InvalidArgumentException
     */
    public function __invoke(Request $request, Closure $next): Response
    {
        $request = $request->withAttribute('test_middleware_passed', true);

        $response = $next($request);

        return $response->withHeader('X-Test-Middleware', 'passed');
    }
}
