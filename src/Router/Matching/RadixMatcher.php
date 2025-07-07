<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Radix-tree matcher for static routes.
 *
 * Node = [
 *     self::CHILDREN => array<string, Node>,
 *     self::ROUTES   => array<verb, CompiledRoute>
 * ]
 *
 * `self::CHILDREN` and `self::ROUTES` are numeric slots (0 / 1) to shave a few
 * bytes per node without losing readability.
 */
final class RadixMatcher implements MatcherInterface
{
    private const CHILDREN = 0;   // array<string, array{array,array}>
    private const ROUTES   = 1;   // array<string, CompiledRoute>

    /** @var array<string, array{array,array}>  Root buckets by host (“*” = any) */
    private array $hosts = [];

    /* -------------------------------------------------------------------------
     * Construction
     * ---------------------------------------------------------------------- */

    public function add(CompiledRoute $route): void
    {
        $host = $route->getDomain() ?? '*';

        // Establish host root
        if (!isset($this->hosts[$host])) {
            $this->hosts[$host] = [[], []];        // [children, routes]
        }
        $node = & $this->hosts[$host];              // ← variable, so reference is OK

        // Walk / create path segments
        foreach ($this->segments($route->getPath()) as $segment) {
            if (!isset($node[self::CHILDREN][$segment])) {
                $node[self::CHILDREN][$segment] = [[], []];
            }
            $node = & $node[self::CHILDREN][$segment];
        }

        // Attach the route at leaf
        $node[self::ROUTES][$route->getMethod()] = $route;
    }

    /* -------------------------------------------------------------------------
     * Matching
     * ---------------------------------------------------------------------- */

    public function match(string $method, string $host, string $path): array
    {
        foreach ([$host, '*'] as $bucket) {
            if (!isset($this->hosts[$bucket])) {
                continue;
            }

            $node   = $this->hosts[$bucket];
            $found  = true;

            foreach ($this->segments($path) as $segment) {
                if (!isset($node[self::CHILDREN][$segment])) {
                    $found = false;                // path breaks here – try next host
                    break;
                }
                $node = $node[self::CHILDREN][$segment];
            }

            if (!$found) {
                continue;                          // try the “*” bucket next
            }

            /* ---- verb gate -------------------------------------------------- */
            if (isset($node[self::ROUTES][$method])) {
                return [$node[self::ROUTES][$method], /* params */ []];
            }

            if ($node[self::ROUTES] !== []) {
                throw new MethodNotAllowedException(
                    $method,
                    $path,
                    array_keys($node[self::ROUTES]),
                );
            }
        }

        throw new RouteNotFoundException($method, $path);
    }

    /* -------------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------- */

    /** Split a path into segments, stripping leading/trailing slashes. */
    private function segments(string $path): array
    {
        $clean = trim($path, '/');
        return $clean === '' ? [] : explode('/', $clean);
    }
}
