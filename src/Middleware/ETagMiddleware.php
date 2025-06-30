<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Request\Request;

/**
 * Adds a strong ETag (sha-1/16) when missing and body is seekable.
 */
final readonly class ETagMiddleware
{
    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);
        if ($resp->hasHeader('ETag')
            || !$resp->getBody()->isSeekable()
            || $resp->getStatusCode() !== 200
        ) {
            return $resp;
        }

        $body = (string) $resp->getBody();
        $etag = Utils::generateEtag($body);

        return $resp->withHeader('ETag', $etag);
    }
}
