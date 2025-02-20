<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Infocyph\Webrick\Http\Response;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Throwable;

/**
 * Catches routing exceptions and returns a standard error response (404, 405, 500).
 * If you want to use your own "ResponseFactory", you could adapt this easily.
 */
class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (RouteNotFoundException $ex) {
            $response = new Response(404, [], null, 'Not Found');
            $response->getBody()->write($ex->getMessage());
            return $response;
        } catch (MethodNotAllowedException $ex) {
            $response = new Response(405, [], null, 'Method Not Allowed');
            $response->getBody()->write($ex->getMessage());
            // Optionally parse "Allowed Methods" from $ex->getMessage()
            return $response;
        } catch (Throwable $ex) {
            $response = new Response(500, [], null, 'Internal Server Error');
            $response->getBody()->write($ex->getMessage());
            return $response;
        }
    }
}
