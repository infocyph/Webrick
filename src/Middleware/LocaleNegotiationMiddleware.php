<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Negotiation\LocaleNegotiator;

/**
 * Picks the best locale and adds:
 *   • request attribute → 'locale'
 *   • Content-Language → response header
 *   • registers Vary: Accept-Language (written by VaryAccumulatorMiddleware)
 */
final readonly class LocaleNegotiationMiddleware
{
    /** @param string[] $supported Ordered by server-side preference */
    public function __construct(
        private array $supported,
        private string $fallback = 'en',
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        // Decide locale via the negotiator (single source of truth)
        [$chosen] = LocaleNegotiator::forRequest(
            $req,
            $this->supported ?: [$this->fallback],
            $this->fallback,
        );

        // expose chosen locale to downstream & register Vary token
        $req = $req->withAttribute('locale', $chosen);
        $req = VaryAccumulatorMiddleware::add($req, 'Accept-Language');

        $resp = $next($req);

        // Write Content-Language; Vary is emitted by the accumulator
        return $resp->withHeader('Content-Language', $chosen);
    }
}
