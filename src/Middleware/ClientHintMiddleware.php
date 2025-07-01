<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Advertise that the server understands modern Client-Hints.
 *
 * Enable only when you actually consume the hints to avoid
 * unnecessary UA entropy. Typical placement: global stack,
 * _after_ security headers.
 */
final readonly class ClientHintMiddleware
{
    /** @param string[] $hints  e.g. ['Sec-CH-UA', 'Sec-CH-UA-Platform'] */
    public function __construct(private array $hints = [
        'Sec-CH-UA', 'Sec-CH-UA-Mobile', 'Sec-CH-UA-Platform',
        'Sec-CH-UA-Arch', 'Sec-CH-UA-Model', 'Sec-CH-UA-Full-Version',
    ]) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);
        return $resp->withHeader('Accept-CH', implode(', ', $this->hints));
    }
}
