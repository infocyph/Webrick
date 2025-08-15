<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Negotiation\LocaleNegotiator;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

final readonly class NegotiationMiddleware
{
    /** @param string[] $produces */
    /** @param string[] $charsets */
    /** @param string[] $locales ordered by server-side preference */
    public function __construct(
        private array $produces = ['application/json', 'text/html'],
        private array $charsets = ['utf-8'],
        private array $locales = ['en'],
        private string $localeFallback = 'en',
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        [$prod, $char] = $this->resolveRouteOverrides($req);

        // 1) Negotiate type & charset (with early 406)
        [$type, $cset, $maybeEarly] = $this->negotiateTypeAndCharset($req, $prod, $char);
        if ($maybeEarly instanceof Response) {
            return $maybeEarly;
        }

        // 2) Negotiate locale & register Vary if it can affect result
        $locale = $this->negotiateLocaleRegisterVary($req);

        // 3) Stash negotiated choices for controllers
        $req = $this->stashChoices($req, $type, $cset, $locale);

        // 4) Downstream
        $resp = $next($req);

        // 5) Ensure Content-Type and append charset when appropriate
        $resp = $this->ensureContentType($resp, $type, $cset);

        // 6) Always set Content-Language reflecting chosen locale
        return $resp->withHeader('Content-Language', $locale);
    }

    /* ───────────────────────── orchestration helpers ───────────────────────── */

    /** @return array{0: string[], 1: string[]} */
    private function resolveRouteOverrides(Request $req): array
    {
        $prod = $this->produces;
        $char = $this->charsets;

        /** @var Produces|null $attr */
        $attr = $req->getAttribute('produces');
        if ($attr instanceof Produces) {
            $prod = $attr->types ?: $prod;
            if (!empty($attr->charsets)) {
                $char = $attr->charsets;
            }
        }
        return [$prod, $char];
    }

    /**
     * @param string[] $prod
     * @param string[] $char
     * @return array{0: string, 1: ?string, 2: ?Response}
     */
    private function negotiateTypeAndCharset(Request $req, array $prod, array $char): array
    {
        // Tiny fast-paths to avoid work:
        $accept = $req->getHeaderLine('Accept');
        $acceptCharset = $req->getHeaderLine('Accept-Charset');

        // Build negotiator only if we need it
        $neg = ($accept !== '' || $acceptCharset !== '')
            ? new ContentNegotiator($req->headers())
            : null;

        // Type
        $type = ($neg !== null)
            ? $neg->preferred($prod)   // may be null
            : ($prod[0] ?? null);

        if ($type === null) {
            // Register Vary before short-circuit 406 so accumulator can write it
            VaryAccumulatorMiddleware::add($req, 'Accept');
            if ($acceptCharset !== '' && $this->charsetMattersForAny($prod)) {
                VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
            }

            return [
                '',
                null,
                new Response(
                    status: 406,
                    headers: ['Content-Type' => 'text/plain; charset=utf-8'],
                    body: new Stream('Not acceptable.')
                ),
            ];
        }

        // Vary: Accept always (negotiation exists even if client didn't send Accept)
        VaryAccumulatorMiddleware::add($req, 'Accept');

        // Charset
        $cset = null;
        if ($acceptCharset !== '' && $this->charsetMattersFor($type)) {
            // Only check if header sent and type cares about charset
            $cset = $this->pickCharset($neg ?? new ContentNegotiator($req->headers()), $char);
            if ($cset !== null) {
                VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
            }
        }

        return [$type, $cset, null];
    }

    private function negotiateLocaleRegisterVary(Request $req): string
    {
        [$locale] = LocaleNegotiator::forRequest(
            $req,
            $this->locales ?: [$this->localeFallback],
            $this->localeFallback,
        );

        // Only vary if client sent the header OR server offers > 1 locale
        $acceptLangPresent = $req->getHeaderLine('Accept-Language') !== '';
        $multiLocales = \count($this->locales) > 1;
        VaryAccumulatorMiddleware::addIf($req, $acceptLangPresent || $multiLocales, 'Accept-Language');

        return $locale;
    }

    private function stashChoices(Request $req, string $type, ?string $cset, string $locale): Request
    {
        return $req
            ->withAttribute('negotiated.type', $type)
            ->withAttribute('negotiated.charset', $cset)
            ->withAttribute('locale', $locale);
    }

    private function ensureContentType(Response $resp, string $type, ?string $cset): Response
    {
        $code = $resp->getStatusCode();
        if ($code === 204 || $code === 304) {
            return $resp;
        }

        $existing = $resp->getHeaderLine('Content-Type');
        if ($existing === '') {
            return $resp->withHeader('Content-Type', $this->composeContentType($type, $cset));
        }

        // Append charset if needed and we negotiated one
        if ($cset === null) {
            return $resp;
        }

        // Compute base type once, case-insensitively
        $semicolonPos = strpos($existing, ';');
        $base = $semicolonPos === false ? $existing : substr($existing, 0, $semicolonPos);
        $baseLower = strtolower($base);

        if (
            stripos($existing, 'charset=') === false
            && $this->charsetMattersFor($baseLower)
            && !$this->isJson($baseLower)
        ) {
            // Preserve original spacing, just append param
            return $resp->withHeader('Content-Type', rtrim($existing) . '; charset=' . $cset);
        }

        return $resp;
    }

    /* ───────────────────────── leaf helpers ───────────────────────── */

    private function pickCharset(ContentNegotiator $neg, array $candidates): ?string
    {
        // candidates are already ordered by server preference
        foreach ($candidates as $cs) {
            // negotiator expects lowercase charsets
            if ($neg->supportsCharset(strtolower($cs))) {
                return $cs;
            }
        }
        return null;
    }

    private function composeContentType(string $type, ?string $charset): string
    {
        // Avoid repeated lowercasing/contains checks
        $typeLower = strtolower($type);
        if ($this->isJson($typeLower)) {
            return $type; // never append for JSON; UTF-8 by spec
        }

        // If controller already included params, trust them
        if (str_contains($type, ';')) {
            return $type;
        }

        $needsCs =
            str_starts_with($typeLower, 'text/') ||
            str_contains($typeLower, 'xml') ||
            $typeLower === 'application/javascript' ||
            $typeLower === 'text/javascript';

        return ($needsCs && $charset) ? "{$type}; charset={$charset}" : $type;
    }

    /** True when charset changes octets on the wire for this media type. */
    private function charsetMattersFor(string $typeLower): bool
    {
        if ($this->isJson($typeLower)) {
            return false;
        }

        return str_starts_with($typeLower, 'text/')
            || str_contains($typeLower, 'xml')
            || $typeLower === 'application/javascript'
            || $typeLower === 'text/javascript';
    }

    /** Conservative check used for 406 short-circuit Vary decision. */
    private function charsetMattersForAny(array $types): bool
    {
        foreach ($types as $t) {
            if ($this->charsetMattersFor(strtolower($t))) {
                return true;
            }
        }
        return false;
    }

    private function isJson(string $typeLower): bool
    {
        return str_starts_with($typeLower, 'application/json');
    }
}
