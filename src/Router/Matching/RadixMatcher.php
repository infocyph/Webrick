<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

final class RadixMatcher implements MatcherInterface
{
    private const CHILDREN = 0;                              // array<string, node>
    private const ROUTES   = 1;                              // array<string, CompiledRoute>

    /** @var array<string, array{0:array,1:array<string,CompiledRoute>}> */
    private array $hosts = [];

    /* -------------------------------------------------------------------------
     *  Construction
     * ---------------------------------------------------------------------- */

    public function add(CompiledRoute $route): void
    {
        $host   = strtolower($route->getDomain() ?? '*');
        $method = strtoupper($route->getMethod());
        $parts  = self::segments($route->getPath());

        /* --- host root ------------------------------------------------------ */
        $this->hosts[$host] ??= [[], []];
        $node = & $this->hosts[$host];               // ← now a pure variable

        /* --- walk / build segments ----------------------------------------- */
        foreach ($parts as $seg) {
            $node[self::CHILDREN][$seg] ??= [[], []];
            $node = & $node[self::CHILDREN][$seg];   // safe reference
        }

        /* --- attach route --------------------------------------------------- */
        $node[self::ROUTES][$method] = $route;
    }

    /* -------------------------------------------------------------------------
     *  Matching
     * ---------------------------------------------------------------------- */

    public function match(string $method, string $host, string $path): array
    {
        $verb  = strtoupper($method);
        $parts = self::segments($path);

        foreach ([strtolower($host), '*'] as $bucket) {
            $node = $this->hosts[$bucket] ?? null;
            if (!$node) {
                continue;
            }

            foreach ($parts as $seg) {
                $node = $node[self::CHILDREN][$seg] ?? null;
                if (!$node) {
                    continue 2;                     // fallback to next host bucket
                }
            }

            /* --- verb gate -------------------------------------------------- */
            if (isset($node[self::ROUTES][$verb])) {
                return [$node[self::ROUTES][$verb], /* params */ []];
            }

            if ($node[self::ROUTES] !== []) {
                throw new MethodNotAllowedException(
                    $verb,
                    $path,
                    array_keys($node[self::ROUTES]),
                );
            }
        }

        throw new RouteNotFoundException($verb, $path);
    }

    /* -------------------------------------------------------------------------
     *  Helpers
     * ---------------------------------------------------------------------- */

    /** Trim leading/trailing slashes and explode once. */
    private static function segments(string $path): array
    {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? [] : explode('/', $trimmed);
    }
}
