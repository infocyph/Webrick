<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;

/**
 * Phase-2 compatibility boundary that gives all existing matcher backends one
 * canonical typed outcome contract. Phase 4 may move this contract directly
 * into the canonical matcher IR without changing kernel semantics.
 */
final readonly class MatcherOutcomeAdapter
{
    public function __construct(private MatcherInterface $matcher) {}

    public function match(string $method, string $host, string $path): MatchOutcome
    {
        $verb = HttpMethodEnum::normalize($method);

        try {
            [$route, $params] = $this->matcher->match($verb, $host, $path);

            return MatchOutcome::found(
                $route,
                $params,
                $verb === HttpMethodEnum::HEAD->value
                    && HttpMethodEnum::normalize($route->getMethod()) === HttpMethodEnum::GET->value,
            );
        } catch (MethodNotAllowedException $exception) {
            return $verb === HttpMethodEnum::OPTIONS->value
                ? MatchOutcome::autoOptions($exception->allowed())
                : MatchOutcome::methodNotAllowed($exception->allowed());
        } catch (RouteNotFoundException) {
            return MatchOutcome::notFound();
        }
    }
}
