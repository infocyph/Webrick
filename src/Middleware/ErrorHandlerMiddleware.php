<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Http\Response;
use Throwable;

/**
 * ErrorHandlerMiddleware that toggles between dev/prod mode.
 * In dev mode => show stack trace; in prod => hide details.
 */
class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $devMode = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (RouteNotFoundException $ex) {
            return $this->buildResponse(404, 'Not Found', $ex);
        } catch (MethodNotAllowedException $ex) {
            $resp = $this->buildResponse(405, 'Method Not Allowed', $ex);
            // Optionally parse "Allowed Methods" from ex->getMessage() to set header
            return $resp;
        } catch (Throwable $ex) {
            return $this->buildResponse(500, 'Internal Server Error', $ex);
        }
    }

    private function buildResponse(int $statusCode, string $reason, Throwable $ex): ResponseInterface
    {
        $response = new Response($statusCode, [], null, $reason);

        if ($this->devMode) {
            // Show debug info
            $body = "[DEV MODE] Exception: " . $ex->getMessage() . "\n\n" . $ex->getTraceAsString();
        } else {
            // Generic message
            $body = "An error occurred. Please try again later.";
            if ($statusCode === 404 || $statusCode === 405) {
                // We can show more specific text for 404/405 if we like
                $body = $ex->getMessage();
            }
        }

        $response->getBody()->write($body);
        return $response;
    }
}
