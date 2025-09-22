<?php

/**
 * Webrick - Content negotiation middleware.
 *
 * Performs Accept/Accept-Charset/Accept-Language negotiation for requests.
 * - Negotiates media type (with early 406 when no compatible type is found).
 * - Negotiates charset when it materially affects wire bytes for the chosen type.
 * - Negotiates locale and sets Content-Language.
 * - Registers appropriate Vary tokens so caches behave correctly.
 * - Ensures Content-Type is present on non-empty responses and appends charset when appropriate.
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Negotiation\ContentTypeNegotiator;
use Infocyph\Webrick\Response\Negotiation\LocaleNegotiator;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

/**
 * Negotiate content type, charset, and locale; set Vary and Content-Language.
 *
 * This middleware reads optional route-level overrides (via the Produces attribute),
 * uses client Accept* headers to negotiate, and exposes the negotiated values to
 * downstream handlers via request attributes.
 */
final readonly class NegotiationMiddleware
{
    /**
     * @param array<int,string> $produces Supported media types (e.g., ['+json','application/json','text/html']).
     * @param array<int,string> $charsets Supported charsets (e.g., ['utf-8']).
     * @param array<int,string> $locales  Supported locales ordered by server preference.
     * @param string            $localeFallback Fallback locale when no match is found.
     */
    public function __construct(
        private array $produces = ['+json', 'application/json', 'text/html'],
        private array $charsets = ['utf-8'],
        private array $locales = ['en'],
        private string $localeFallback = 'en',
    ) {
    }

    /**
     * Perform negotiation, stash results on the request, and normalize response headers.
     *
     * Flow:
     * 1) Determine route-level overrides for produced media types/charsets.
     * 2) Negotiate media type and charset; return early 406 if no compatible type.
     * 3) Negotiate locale and register Vary when it can affect the result.
     * 4) Pass control to downstream; then ensure Content-Type and set Content-Language.
     *
     * @param Request $req  Incoming request.
     * @param Closure $next Next handler.
     *
     * @return Response Response with normalized headers.
     */
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

    /**
     * Whether charset meaningfully affects wire bytes for the given media type.
     *
     * JSON is excluded since the spec fixes UTF-8.
     *
     * @param string $typeLower Lower-cased media type.
     *
     * @return bool True when charset matters for this type.
     */
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

    /**
     * Conservative check used for 406 short-circuit Vary decision.
     *
     * @param array<int,string> $types
     *
     * @return bool True if any type would have charset significance.
     */
    private function charsetMattersForAny(array $types): bool
    {
        return array_any($types, fn ($t) => $this->charsetMattersFor(strtolower($t)));
    }

    /**
     * Compose a Content-Type header value, appending charset when applicable.
     *
     * For JSON types, no charset parameter is appended (UTF-8 by spec).
     *
     * @param string      $type
     * @param string|null $charset
     *
     * @return string Header value.
     */
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

    /**
     * Ensure Content-Type is present and append charset when appropriate.
     *
     * @param Response    $resp
     * @param string      $type
     * @param string|null $cset
     *
     * @return Response Normalized response.
     */
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

    /**
     * Identify JSON media types (including structured suffix).
     *
     * @param string $typeLower Lower-cased media type.
     *
     * @return bool True if JSON or +json.
     */
    private function isJson(string $typeLower): bool
    {
        return str_starts_with($typeLower, 'application/json') || str_ends_with($typeLower, '+json');
    }

    /**
     * Negotiate locale and register Vary when the outcome can vary by request.
     *
     * @param Request $req
     *
     * @return string Selected locale.
     */
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

    /**
     * Negotiate media type and charset; return early 406 when unsupported.
     *
     * @param string[] $prod Supported media types.
     * @param string[] $char Supported charsets.
     *
     * @return array{0: string, 1: ?string, 2: ?Response} [type, charset, earlyResponse]
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

    /* ───────────────────────── leaf helpers ───────────────────────── */

    /**
     * Pick the first supported charset from the provided candidates.
     *
     * @param ContentNegotiator  $neg        Request negotiator.
     * @param array<int,string>  $candidates Candidate charset names.
     *
     * @return string|null Selected charset or null.
     */
    private function pickCharset(ContentNegotiator $neg, array $candidates): ?string
    {
        foreach ($candidates as $cs) {
            if ($neg->supportsCharset(strtolower($cs))) {
                return $cs;
            }
        }
        return null;
    }

    /* ───────────────────────── orchestration helpers ───────────────────────── */

    /**
     * Read route-level overrides for produces/charsets from a Produces attribute.
     *
     * @param Request $req
     *
     * @return array{0: string[], 1: string[]} Tuple of [produces, charsets].
     */
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
     * Store negotiated parameters on the request for downstream use.
     *
     * @param Request     $req
     * @param string      $type
     * @param string|null $cset
     * @param string      $locale
     *
     * @return Request Request augmented with negotiation attributes.
     */
    private function stashChoices(Request $req, string $type, ?string $cset, string $locale): Request
    {
        return $req
            ->withAttribute('negotiated.type', $type)
            ->withAttribute('negotiated.charset', $cset)
            ->withAttribute('locale', $locale);
    }
}
