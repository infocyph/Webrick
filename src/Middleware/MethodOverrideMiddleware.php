<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

/**
 * Supports HTML-form method spoofing via:
 *   – `X-HTTP-Method-Override` header, **or**
 *   – `_method` field in `application/x-www-form-urlencoded` bodies.
 *
 * The override happens **before** routing, so Webrick will match the
 * intended verb.
 */
final readonly class MethodOverrideMiddleware
{
    public function __construct(private string $header = 'X-HTTP-Method-Override') {}

    public function __invoke(Request $request, Closure $next): Response
    {
        $newMethod = $request->getHeaderLine($this->header);

        // Fallback to form field for classic POST forms
        if ($newMethod === '' && $request::getMethodParamOverride() && is_array($request->getParsedBody())) {
            $body = $request->getParsedBody();
            $newMethod = $body['_method'] ?? '';
        }

        if ($newMethod !== '') {
            $request = $request->withMethod(strtoupper($newMethod));
        }

        return $next($request);
    }
}
