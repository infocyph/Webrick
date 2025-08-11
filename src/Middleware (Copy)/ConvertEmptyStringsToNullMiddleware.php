<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

/**
 * Mirrors Laravel’s behaviour: converts `''` → `null` in request data.
 *
 * Should run *after* `TrimStringsMiddleware`.
 */
final readonly class ConvertEmptyStringsToNullMiddleware
{
    public function __invoke(Request $req, Closure $next): Response
    {
        $body = $req->getParsedBody();
        if (\is_array($body)) {
            $body = self::nullify($body);
            $req = $req->withParsedBody($body);
        }

        $query = $req->getQueryParams();
        if ($query) {
            $req = $req->withQueryParams(self::nullify($query));
        }

        return $next($req);
    }

    private static function nullify(array $data): array
    {
        array_walk_recursive(
            $data,
            static function (&$v): void {
                if ($v === '') {
                    $v = null;
                }
            },
        );
        return $data;
    }
}
