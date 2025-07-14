<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * High-level multiplexer that dispatches to the most efficient matcher:
 *
 *   1. StaticMatcher  → O(1) literal hits
 *   2. RadixMatcher   → huge static sets once route-count > $promoteAfter
 *   3. DynamicMatcher → parameterised fall-back
 *
 * Order matters: cheapest check first.
 */
final class DomainMatcher implements MatcherInterface
{
    private StaticMatcher  $static;
    private DynamicMatcher $dynamic;
    private ?RadixMatcher  $radix;

    /** Number of static routes seen so far. */
    private int $staticCount = 0;

    /** Promote to radix once this many static routes have been registered. */
    private int $promoteAfter;

    /**
     * @param bool $useRadix       enable/disable radix layer entirely
     * @param int  $promoteAfter   route-count cut-off (bench-driven, default = 2048)
     */
    public function __construct(bool $useRadix = false, int $promoteAfter = 1024)
    {
        $this->static        = new StaticMatcher();
        $this->dynamic       = new DynamicMatcher();
        $this->radix         = $useRadix ? new RadixMatcher() : null;
        $this->promoteAfter  = max(1, $promoteAfter);
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

        /* ---- static set --------------------------------------------------- */
        $this->staticCount++;

        if ($this->radix !== null && $this->staticCount > $this->promoteAfter) {
            // From now on, static routes go straight into the radix tree
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
        $allowedVerbs = [];

        /* ---- static ------------------------------------------------------ */
        try {
            return $this->static->match($method, $host, $path);
        } catch (MethodNotAllowedException $e) {
            $allowedVerbs = array_merge($allowedVerbs, $e->allowed());
        } catch (RouteNotFoundException) {
            /* escalate */
        }

        /* ---- radix ------------------------------------------------------- */
        if ($this->radix !== null && $this->staticCount > $this->promoteAfter) {
            try {
                return $this->radix->match($method, $host, $path);
            } catch (MethodNotAllowedException $e) {
                $allowedVerbs = array_merge($allowedVerbs, $e->allowed());
            } catch (RouteNotFoundException) {
                /* fall-back to dynamic */
            }
        }

        /* ---- dynamic ----------------------------------------------------- */
        try {
            return $this->dynamic->match($method, $host, $path);
        } catch (MethodNotAllowedException $e) {
            $allowedVerbs = array_merge($allowedVerbs, $e->allowed());
        }

        /* ---- nothing matched: 405 vs 404 -------------------------------- */
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
