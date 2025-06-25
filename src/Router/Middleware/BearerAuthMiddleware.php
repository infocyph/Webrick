<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Minimum-viable bearer-token checker.
 * Extend/replace with real auth logic in production.
 */
final class BearerAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
    {
        $hdr = $r->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+\S+$/', $hdr)) {
            throw new RuntimeException('Unauthorized', 401);
        }
        return $h->handle($r);
    }
}
