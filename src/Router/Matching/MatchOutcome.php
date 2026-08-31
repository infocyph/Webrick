<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Route\CompiledRoute;
use LogicException;

/**
 * Immutable generic routing decision.
 *
 * Strict compiled success uses the compact matcher result contract instead of
 * allocating this object.
 *
 * @phpstan-type RouteParams array<string,string>
 */
final readonly class MatchOutcome
{
    /**
     * @param RouteParams $params
     * @param list<string> $allowed
     */
    private function __construct(
        public MatchOutcomeType $type,
        public ?CompiledRoute $route = null,
        public array $params = [],
        public array $allowed = [],
        public bool $headFallback = false,
    ) {}

    /** @param list<string> $allowed */
    public static function autoOptions(array $allowed): self
    {
        return new self(MatchOutcomeType::AUTO_OPTIONS, allowed: $allowed);
    }

    /**
     * @param RouteParams $params
     */
    public static function found(CompiledRoute $route, array $params = [], bool $headFallback = false): self
    {
        return new self(MatchOutcomeType::FOUND, $route, $params, headFallback: $headFallback);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(MatchOutcomeType::METHOD_NOT_ALLOWED, allowed: $allowed);
    }

    public static function notFound(): self
    {
        return new self(MatchOutcomeType::NOT_FOUND);
    }

    public function requireRoute(): CompiledRoute
    {
        if (!$this->route instanceof CompiledRoute) {
            throw new LogicException('Match outcome does not contain a route.');
        }

        return $this->route;
    }
}
