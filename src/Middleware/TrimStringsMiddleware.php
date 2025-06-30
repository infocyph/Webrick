<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Recursively `trim()`s *all* string inputs (query + body + uploaded names).
 *
 * Works only when `$request->getParsedBody()` is an array/object;
 * JSON bodies are left untouched because controllers usually want raw data.
 */
final readonly class TrimStringsMiddleware
{
    public function __invoke(ServerRequestInterface $req, Closure $next): Response
    {
        $body = $req->getParsedBody();
        if (\is_array($body)) {
            $body = self::trimRecursive($body);
            $req  = $req->withParsedBody($body);
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
        foreach ($data as $k => $v) {
            if (\is_string($v)) {
                $data[$k] = trim($v);
            } elseif (\is_array($v)) {
                $data[$k] = self::trimRecursive($v);
            }
        }
        return $data;
    }
}
