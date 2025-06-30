<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Gzip-encodes the body if the client accepts it
 * and the response isn’t already encoded / streamed.
 */
final readonly class CompressionMiddleware
{
    private const MIN_SIZE = 1024;  // don’t bother for tiny payloads

    public function __invoke(ServerRequestInterface $req, Closure $next): Response
    {
        $accept = $req->getHeaderLine('Accept-Encoding');
        $supportsGzip = str_contains($accept, 'gzip');

        $resp = $next($req);

        if (!$supportsGzip
            || $resp->hasHeader('Content-Encoding')
            || $resp->getBody()->getSize() !== null && $resp->getBody()->getSize() < self::MIN_SIZE
        ) {
            return $resp;
        }

        /* ------ compress -------------------------------------------------- */
        $payload = (string) $resp->getBody();
        $gz = gzencode($payload, 6, ZLIB_ENCODING_GZIP);
        if ($gz === false) {
            return $resp;                       // fallback – extremely rare
        }

        return $resp
            ->withBody(new Stream($gz))
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Content-Length', (string) strlen($gz))
            ->withHeader('Vary', 'Accept-Encoding');
    }
}
