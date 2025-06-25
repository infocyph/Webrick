<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Infocyph\Webrick\Http\Response;
use Infocyph\Webrick\Http\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Converts any uncaught Throwable into a JSON error response:
 *   { "error": { "message": "...", "code": 400 } }
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
    {
        try {
            return $h->handle($r);
        } catch (Throwable $e) {
            $code   = ($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500;
            $body   = json_encode(['error' => ['message' => $e->getMessage(), 'code' => $code]], JSON_UNESCAPED_SLASHES);

            return (new Response($code))
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new Stream($body ?: 'null'));
        }
    }
}
