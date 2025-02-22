<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Infocyph\Webrick\Http\Response;

/**
 * Automatically redirects any trailing slash to its slash-less equivalent,
 * except for the root "/" path.
 */
class TrailingSlashRedirectMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // If there's a trailing slash (and it's not just "/")
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $newPath = rtrim($path, '/');
            $newUri  = $request->getUri()->withPath($newPath);

            // Return a 301 redirect
            return (new Response(301))
                ->withHeader('Location', (string) $newUri);
        }

        return $handler->handle($request);
    }
}
