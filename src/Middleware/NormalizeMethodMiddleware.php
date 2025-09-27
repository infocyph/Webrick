<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * NormalizeMethodMiddleware
 *
 * Copies Request::getEffectiveMethod() into Request::getMethod() so all
 * downstream consumers (routers/guards/middleware) that read getMethod()
 * see HEAD→GET and any header/_method overrides.
 *
 * Safe, idempotent, and cheap.
 *
 * Place early, before routing.
 */
final class NormalizeMethodMiddleware
{
    public function __invoke(Request $req, Closure $next): Response
    {
        return $next($req->withMethod($req->getEffectiveMethod()));
    }
}
