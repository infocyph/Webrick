<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Headers\Language;
use Psr\Http\Message\ServerRequestInterface;
use Infocyph\Webrick\Response\Response;

/**
 * Picks the best locale and adds:
 *   • request attribute  → 'locale'
 *   • Content-Language   → response header
 *   • Vary: Accept-Language
 */
final readonly class LocaleNegotiationMiddleware
{
    /** @param string[] $supported ordered preference list */
    public function __construct(
        private array $supported,
        private string $fallback = 'en'
    ) {}

    public function __invoke(ServerRequestInterface $req, Closure $next): Response
    {
        $accept = $req->getHeaderLine('Accept-Language');
        $chosen = Language::negotiate($this->supported, $accept);

        $req = $req->withAttribute('locale', $chosen);

        $resp = $next($req);
        return $resp
            ->withHeader('Content-Language', $chosen)
            ->withHeader('Vary', 'Accept-Language');
    }
}
