<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

/**
 * Redirects every incoming request to HTTPS when `$productionMode` is true.
 */
final readonly class HttpsEnforceMiddleware
{
    public function __construct(private bool $productionMode = true) {}

    public function __invoke(Request $request, Closure $next): Response
    {
        if (!$this->productionMode) {
            return $next($request);
        }

        $uri = $request->getUri();
        if ($uri->getScheme() !== 'https') {
            $target = $uri->withScheme('https')->withPort(443);

            return new Response(
                status: 308,                                        // permanent redirect preserving verb
                headers: ['Location' => (string)$target],
            );
        }

        return $next($request);
    }
}
