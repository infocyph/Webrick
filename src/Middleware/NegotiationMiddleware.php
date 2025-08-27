<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Negotiation\LocaleNegotiator;
use Infocyph\Webrick\Response\Negotiation\ContentTypeNegotiator;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

final readonly class NegotiationMiddleware
{
    /** @param string[] $produces */
    /** @param string[] $charsets */
    /** @param string[] $locales ordered by server-side preference */
    public function __construct(
        private array $produces = ['+json','application/json', 'text/html'],
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
        return $resp->withSmartHeader('Content-Language', $locale);
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
        // ---------- Media type (delegate to your negotiator) ----------
        // chooseFromRequest() returns first supported if Accept is empty/*/*, or null on mismatch.
        $type = ContentTypeNegotiator::chooseFromRequest($req, $prod);

        if ($type === null) {
            // Register Vary before short-circuit 406 so accumulator can write it
            VaryAccumulatorMiddleware::add($req, 'Accept');
            if ($req->getHeaderLine('Accept-Charset') !== '' && $this->charsetMattersForAny($prod)) {
                VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
            }

            return [
                '',
                null,
                // Response(statusCode, body, headers)
                new Response(
                    406,
                    new Stream('Not acceptable.'),
                    ['Content-Type' => 'text/plain; charset=utf-8'],
                ),
            ];
        }

        // We negotiated a type → always vary on Accept
        VaryAccumulatorMiddleware::add($req, 'Accept');

        // ---------- Charset (delegate to ContentNegotiator supportsCharset) ----------
        $cset = null;
        $acceptCharset = $req->getHeaderLine('Accept-Charset');
        $typeLower = strtolower($type);

        if ($acceptCharset !== '' && $this->charsetMattersFor($typeLower)) {
            $neg = new ContentNegotiator($req->headers());
            $cset = $this->pickCharset($neg, $char);
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
            return $resp->withSmartHeader('Content-Type', $this->composeContentType($type, $cset));
        }

        // Append charset if needed and we negotiated one
        if ($cset === null) {
            return $resp;
        }

        $semicolonPos = strpos($existing, ';');
        $base = $semicolonPos === false ? $existing : substr($existing, 0, $semicolonPos);
        $baseLower = strtolower($base);

        if (
            stripos($existing, 'charset=') === false
            && $this->charsetMattersFor($baseLower)
            && !$this->isJson($baseLower)
        ) {
            return $resp->withSmartHeader('Content-Type', rtrim($existing) . '; charset=' . $cset);
        }

        return $resp;
    }

    /* ───────────────────────── leaf helpers ───────────────────────── */

    private function pickCharset(ContentNegotiator $neg, array $candidates): ?string
    {
        foreach ($candidates as $cs) {
            if ($neg->supportsCharset(strtolower($cs))) {
                return $cs;
            }
        }
        return null;
    }

    private function composeContentType(string $type, ?string $charset): string
    {
        $typeLower = strtolower($type);
        if ($this->isJson($typeLower)) {
            return $type; // never append for JSON; UTF-8 by spec
        }
        if (str_contains($type, ';')) {
            return $type; // controller already set params
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
        return array_any($types, fn ($t) => $this->charsetMattersFor(strtolower($t)));
    }

    private function isJson(string $typeLower): bool
    {
        return str_starts_with($typeLower, 'application/json') || str_ends_with($typeLower, '+json');
    }
}
