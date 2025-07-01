<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;
use RuntimeException;

/**
 * Dev-only guard: aborts if a response violates the internal HTTP spec.
 * - Body without `Content-Type`
 * - Body present on 204/304
 * - Missing `Vary` on compressed responses
 */
final readonly class ResponseLinterMiddleware
{
    public function __construct(private bool $enabled = true) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);

        if (!$this->enabled) {
            return $resp;
        }

        $code = $resp->getStatusCode();
        $bodyLen = $resp->getBody()->getSize() ?? strlen((string)$resp->getBody());

        /* Content-Type required */
        if ($bodyLen > 0 && $resp->getHeaderLine('Content-Type') === '') {
            throw new RuntimeException('Linter: missing Content-Type header');
        }

        /* Body forbidden for 204/304 */
        if (in_array($code, [204, 304], true) && $bodyLen > 0) {
            throw new RuntimeException("Linter: body not allowed on {$code}");
        }

        /* Vary on compressed */
        if ($resp->hasHeader('Content-Encoding')
            && stripos($resp->getHeaderLine('Vary'), 'accept-encoding') === false
        ) {
            throw new RuntimeException('Linter: compressed but missing Vary: Accept-Encoding');
        }

        return $resp;
    }
}
