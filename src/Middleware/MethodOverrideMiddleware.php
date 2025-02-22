<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Allows overriding the HTTP method with an HTTP header or form field,
 * commonly used with legacy clients or forms that only support GET/POST.
 */
class MethodOverrideMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $overrideHeader = 'X-HTTP-Method-Override')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $override = $request->getHeaderLine($this->overrideHeader);
        if ($override) {
            $request = $request->withMethod($override);
        }

        return $handler->handle($request);
    }
}
