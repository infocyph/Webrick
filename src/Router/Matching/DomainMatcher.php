<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * High-level multiplexer that delegates to the most efficient matcher:
 *
 *   1. StaticMatcher  → O(1) literal hits
 *   2. RadixMatcher   → optional huge static sets (threshold-based)
 *   3. DynamicMatcher → parameterised fall-back
 *
 * Order matters: cheapest check first.
 */
final class DomainMatcher implements MatcherInterface
{
    private StaticMatcher  $static;
    private DynamicMatcher $dynamic;
    private ?RadixMatcher  $radix;

    /** Minimum segments for a route to be stored in the radix tree. */
    private int $radixThreshold;

    public function __construct(bool $useRadix = false, int $radixThreshold = 2)
    {
        $this->static         = new StaticMatcher();
        $this->dynamic        = new DynamicMatcher();
        $this->radix          = $useRadix ? new RadixMatcher() : null;
        $this->radixThreshold = max(1, $radixThreshold);
    }

    /* ---------------------------------------------------------------------
     * Building
     * ------------------------------------------------------------------ */

    public function add(CompiledRoute $route): void
    {
        if ($route->isDynamic()) {
            $this->dynamic->add($route);
            return;
        }

        if ($this->radix !== null && $route->getPathLength() >= $this->radixThreshold) {
            $this->radix->add($route);
            return;
        }

        $this->static->add($route);
    }

    /* ---------------------------------------------------------------------
     * Matching
     * ------------------------------------------------------------------ */

    public function match(string $method, string $host, string $path): array
    {
        // Collect verbs that matched the path but not the method
        $allowedVerbs = [];

        /* ---- static ------------------------------------------------------ */
        try {
            return $this->static->match($method, $host, $path);
        } catch (MethodNotAllowedException $e) {
            $allowedVerbs = array_merge($allowedVerbs, $e->allowed());
        } catch (RouteNotFoundException) {
            /* ignore – escalate */
        }

        /* ---- radix (optional) -------------------------------------------- */
        if ($this->radix !== null) {
            try {
                return $this->radix->match($method, $host, $path);
            } catch (MethodNotAllowedException $e) {
                $allowedVerbs = array_merge($allowedVerbs, $e->allowed());
            } catch (RouteNotFoundException) {
                /* ignore – fall back to dynamic */
            }
        }

        /* ---- dynamic (final) --------------------------------------------- */
        try {
            return $this->dynamic->match($method, $host, $path);
        } catch (MethodNotAllowedException $e) {
            $allowedVerbs = array_merge($allowedVerbs, $e->allowed());
        } catch (RouteNotFoundException) {
            // nothing – handled below
        }

        /* ---- nothing matched: decide 405 vs 404 -------------------------- */
        if ($allowedVerbs !== []) {
            throw new MethodNotAllowedException(
                $method,
                $path,
                array_values(array_unique($allowedVerbs)),
            );
        }

        throw new RouteNotFoundException($method, $path);
    }
}
