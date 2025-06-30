<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Infocyph\Webrick\Request\Request;

/**
 * Verifies the CSRF token on **state-changing** verbs.
 *
 * – GET|HEAD|OPTIONS are skipped (idempotent).
 * – Uses Request::matchesCsrfToken() helper you already have.
 */
final readonly class CsrfMiddleware
{
    /** Attribute name used by downstream code to disable check on a route. */
    public const BYPASS_ATTR = '_csrf_bypass';

    public function __invoke(Request $req, Closure $next): Response
    {
        if ($req->getAttribute(self::BYPASS_ATTR, false) === true) {
            return $next($req);
        }

        if (!in_array($req->getMethod(), ['POST','PUT','PATCH','DELETE'], true)) {
            return $next($req);
        }

        $facade = $req instanceof Request ? $req : Request::fake()->withParsedBody([]);
        // If we had to fake, copy headers/body/cookies onto facade
        if (!$req instanceof Request) {
            $facade = new Request(
                $req->getMethod(),
                $req->getUri(),
                $req->getServerParams(),
                $req->getHeaders(),
            )
                ->withCookieParams($req->getCookieParams())
                ->withParsedBody($req->getParsedBody() ?? [])
                ->withBody($req->getBody());
        }

        if (!$facade->matchesCsrfToken()) {
            return new Response(
                status  : 419,
                headers : ['Content-Type' => 'text/plain; charset=utf-8'],
                body    : new Stream('CSRF token mismatch.')
            );
        }

        return $next($req);
    }
}
