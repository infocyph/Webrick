<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Infocyph\Webrick\Response\Response;

/**
 * Logs a single line per request in Apache combined-style.
 */
final readonly class RequestLoggingMiddleware
{
    public function __construct(private LoggerInterface $log = new NullLogger())
    {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $start = microtime(true);
        $resp = $next($req);
        $time = (int)((microtime(true) - $start) * 1000);

        $ip = $req->getAttribute('client_ip')
            ?? $req->getServerParams()['REMOTE_ADDR'] ?? '-';
        $fromProxy = $req->getAttribute('is_trusted_proxy') ? 'proxy' : 'direct';
        $method = $req->getMethod();
        $uri = $req->getUri()->getPath();
        $code = $resp->getStatusCode();
        $len = $resp->getBody()->getSize() ?? '-';

        $this->log->info(
            sprintf(
                '%s (%s) "%s %s" %d %s %dms',
                $ip,
                $fromProxy,
                $method,
                $uri,
                $code,
                $len,
                $time,
            ),
        );

        return $resp;
    }
}
