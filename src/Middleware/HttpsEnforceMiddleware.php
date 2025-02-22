<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Infocyph\Webrick\Http\Response;

class HttpsEnforceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $productionMode = true)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->productionMode) {
            // No enforcement in dev mode
            return $handler->handle($request);
        }

        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        if (strtolower($scheme) !== 'https') {
            // Redirect to https
            $httpsUri = $uri->withScheme('https')->withPort(443);
            $response = new Response(302, [], null, 'Found');
            return $response->withHeader('Location', (string)$httpsUri);
        }

        return $handler->handle($request);
    }
}
