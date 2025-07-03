<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Headers\Language;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Picks the best locale and adds:
 *   • request attribute  → 'locale'
 *   • Content-Language   → response header
 *   • Vary: Accept-Language
 */
final readonly class LocaleNegotiationMiddleware
{
    /** @param string[] $supported Ordered by server-side preference */
    public function __construct(
        private array  $supported,
        private string $fallback = 'en',
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $chosen = Language::negotiate(
            $this->supported ?: [$this->fallback],          // ensure non-empty
            $req->getHeaderLine('Accept-Language'),
        ) ?: $this->fallback;                               // extra guard

        $req  = $req->withAttribute('locale', $chosen);
        $resp = $next($req);

        return $resp
            ->withHeader('Content-Language', $chosen)
            ->withHeader('Vary', 'Accept-Language');
    }
}
