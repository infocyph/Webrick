<?php

/**
 * Webrick - Content negotiation middleware.
 *
 * Performs Accept/Accept-Charset/Accept-Language negotiation for requests.
 * - Negotiates media type (with early 406 when no compatible type is found).
 * - Negotiates charset when it materially affects wire bytes for the chosen type.
 * - Negotiates locale (multi-source) and sets Content-Language.
 * - Registers appropriate Vary tokens so caches behave correctly.
 * - Ensures Content-Type is present on non-empty responses and appends charset when appropriate.
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Negotiation\ContentTypeNegotiator;
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
    /** @var array<int,string> */
    private array $produces;

    /**
     * @param array<int,string> $produces Supported media types (e.g., ['+json','application/json','text/html']).
     * @param array<int,string> $charsets Supported charsets (e.g., ['utf-8']).
     * @param array<int,string> $locales Supported locales ordered by server preference.
     * @param string $localeFallback Fallback locale when no match is found.
     */
    public function __construct(
        array $produces = [],
        private array $charsets = ['utf-8'],
        private array $locales = ['en'],
        private string $localeFallback = 'en',
    ) {
        $this->produces = $produces !== []
            ? $produces
            : ['+json', MediaTypeEnum::JSON->value, MediaTypeEnum::HTML->base()];
    }

    /**
     * Perform negotiation, stash results on the request, and normalize response headers.
     *
     * Flow:
     * 1) Determine route-level overrides for produced media types/charsets.
     * 2) Negotiate media type and charset; return early 406 if no compatible type.
     * 3) Negotiate locale (multi-source) and register Vary based on the source.
     * 4) Pass control to downstream; then ensure Content-Type and set Content-Language.
     *
     * @param Request $req Incoming request.
     * @param Closure(Request):Response $next
     * @return Response Response with normalized headers.
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        [$prod, $char] = $this->resolveRouteOverrides($req);

        // 1) Negotiate type & charset (with early 406)
        [$req, $type, $cset, $maybeEarly] = $this->negotiateTypeAndCharset($req, $prod, $char);
        if ($maybeEarly instanceof Response) {
            return $maybeEarly;
        }

        // 2) Negotiate locale & register Vary based on source
        [$req, $locale] = $this->negotiateLocaleRegisterVary($req);

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
     * @param array<string> $types
     * @return bool True if any type would have charset significance.
     */
    private function charsetMattersForAny(array $types): bool
    {
        return array_any(
            $types,
            fn(string $t): bool => $this->charsetMattersFor(strtolower($t)),
        );
    }

    /**
     * Compose a Content-Type header value, appending charset when applicable.
     *
     * For JSON types, no charset parameter is appended (UTF-8 by spec).
     *
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

        $needsCs
            = str_starts_with($typeLower, 'text/')
            || str_contains($typeLower, 'xml')
            || $typeLower === 'application/javascript'
            || $typeLower === 'text/javascript';

        return ($needsCs && $charset) ? "{$type}; charset={$charset}" : $type;
    }

    /**
     * Ensure Content-Type is present and append charset when appropriate.
     *
     *
     * @return Response Normalized response.
     */
    private function ensureContentType(Response $resp, string $type, ?string $cset): Response
    {
        $code = $resp->getStatusCode();
        if (\in_array($code, [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value], true)) {
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
     * @return bool True if JSON or +json.
     */
    private function isJson(string $typeLower): bool
    {
        return MediaTypeEnum::isJsonLike($typeLower);
    }

    /**
     * Negotiate locale and register Vary when the outcome can vary by request.
     *
     * Uses Request::detectLocale() to consider attr/route/query/cookie/header/default,
     * and varies only on the headers actually involved.
     *
     *
     * @return array{0:Request,1:string} Updated request + selected locale.
     */
    private function negotiateLocaleRegisterVary(Request $req): array
    {
        // Detect locale with multi-source resolution; keep default order.
        [$locale, $source] = $req->detectLocale(
            $this->locales ?: [$this->localeFallback],
            $this->localeFallback,
        );
        $locale = $locale !== '' ? $locale : $this->localeFallback;

        // Register Vary selectively based on source
        if ($source === 'header') {
            $req = VaryAccumulatorMiddleware::add($req, 'Accept-Language');
        } elseif ($source === 'cookie') {
            // don’t vary on Cookie for public caches; mark as personalized for your cache/store layer
            $req = $req->withAttribute('personalized', true);
        }

        return [$req, $locale];
    }

    /**
     * Negotiate media type and charset; return early 406 when unsupported.
     *
     * @param string[] $prod Supported media types.
     * @param string[] $char Supported charsets.
     * @return array{0: Request, 1: string, 2: ?string, 3: ?Response} [request, type, charset, earlyResponse]
     */
    private function negotiateTypeAndCharset(Request $req, array $prod, array $char): array
    {
        // ---------- Media type ----------
        $type = ContentTypeNegotiator::chooseFromRequest($req, $prod);

        if ($type === null) {
            // Register Vary before short-circuit 406 so accumulator can write it
            $req = VaryAccumulatorMiddleware::add($req, 'Accept');
            $early = new Response(
                StatusEnum::NOT_ACCEPTABLE->value,
                new Stream('Not acceptable.'),
                ['Content-Type' => MediaTypeEnum::PLAIN->value],
            );
            $early = $early->withSmartHeader('Vary', 'Accept');

            if ($req->getHeaderLine('Accept-Charset') !== '' && $this->charsetMattersForAny($prod)) {
                $req = VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
                $early = $early->withSmartHeader('Vary', 'Accept-Charset');
            }

            return [$req, '', null, $early];
        }

        // We negotiated a type → always vary on Accept
        $req = VaryAccumulatorMiddleware::add($req, 'Accept');

        // ---------- Charset ----------
        $cset = null;
        $typeLower = strtolower($type);
        $acceptCharset = $req->getHeaderLine('Accept-Charset');

        if ($acceptCharset !== '' && $this->charsetMattersFor($typeLower)) {
            $neg = new ContentNegotiator($req->headers());
            $cset = $this->pickCharset($neg, $char);
            if ($cset !== null) {
                $req = VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
            }
        }

        // Default when charset matters but client didn't specify: prefer UTF-8, else first supported.
        if ($cset === null && $this->charsetMattersFor($typeLower)) {
            $lower = array_map(strtolower(...), $char);
            $cset = in_array('utf-8', $lower, true) ? 'utf-8' : ($char[0] ?? null);
            // No Vary on Accept-Charset here; we used a server default.
        }

        return [$req, $type, $cset, null];
    }

    /* ───────────────────────── leaf helpers ───────────────────────── */

    /**
     * Pick the first supported charset from the provided candidates.
     *
     * @param ContentNegotiator $neg Request negotiator.
     * @param array<string> $candidates Candidate charset names.
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
