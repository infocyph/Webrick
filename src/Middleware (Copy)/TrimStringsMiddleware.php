<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

/**
 * Recursively `trim()`s *all* string inputs (query + body + uploaded names).
 *
 * Works only when `$request->getParsedBody()` is an array/object;
 * JSON bodies are left untouched because controllers usually want raw data.
 */
final readonly class TrimStringsMiddleware
{
    public function __invoke(Request $req, Closure $next): Response
    {
        $body = $req->getParsedBody();
        if (\is_array($body)) {
            $body = self::trimRecursive($body);
            $req = $req->withParsedBody($body);
        }

        // query params
        $query = $req->getQueryParams();
        if ($query) {
            $req = $req->withQueryParams(self::trimRecursive($query));
        }

        return $next($req);
    }

    private static function trimRecursive(array $data): array
    {
        array_walk_recursive(
            $data,
            static function (&$v): void {
                if (is_string($v)) {
                    $v = trim($v);
                }
            },
        );
        return $data;
    }
}
