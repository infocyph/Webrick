<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Uri;
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

        $payload = (string)$resp->getBody();
        $qs = Uri::normalizeQueryString($req->getUri()->getQuery());
        $etag = Utils::generateEtag($payload . '#' . $qs);

        return $resp->withHeader('ETag', $etag);
    }
}
