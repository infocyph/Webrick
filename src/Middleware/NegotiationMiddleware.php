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

/**
 * Content-Type + Charset + Locale negotiation in one place.
 *
 * • Accept             → negotiated.type          (406 if no match)
 * • Accept-Charset     → negotiated.charset       (optional)
 * • Accept-Language    → locale                   (always chosen; fallback)
 *
 * Also ensures:
 *   - Vary accumulation for Accept / Accept-Charset (when relevant) / Accept-Language
 *   - Response has Content-Language
 *   - Response gets Content-Type if controller forgot
 *   - If controller set a text/xml/js Content-Type without charset, we append one.
 */
final class NegotiationMiddleware
{
    /** @param string[] $produces */
    /** @param string[] $charsets */
    /** @param string[] $locales  ordered by server-side preference */
    public function __construct(
        private array $produces = ['application/json', 'text/html'],
        private array $charsets = ['utf-8'],
        private array $locales  = ['en'],
        private string $localeFallback = 'en',
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        // Route attribute override (optional)
        $prod = $this->produces;
        $char = $this->charsets;

        /** @var Produces|null $attr */
        $attr = $req->getAttribute('produces');
        if ($attr instanceof Produces) {
            $prod = $attr->types ?: $prod;
            if ($attr->charsets !== null && $attr->charsets !== []) {
                $char = $attr->charsets;
            }
        }

        // 1) Accept / Accept-Charset
        $neg = new ContentNegotiator($req->headers());
        $type = $neg->preferred($prod);                 // null ⇒ 406
        $cset = $this->pickCharset($neg, $char);        // may be null

        if ($type === null) {
            // Register what we varied on before short-circuiting,
            // so VaryAccumulator (outer in the stack) can write Vary.
            VaryAccumulatorMiddleware::add($req, 'Accept');
            if ($req->getHeaderLine('Accept-Charset') !== '' && $this->charsetMattersForAny($prod)) {
                VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
            }

            return new Response(
                status: 406,
                headers: ['Content-Type' => 'text/plain; charset=utf-8'],
                body: new Stream('Not acceptable.'),
            );
        }

        // Vary: Accept always
        VaryAccumulatorMiddleware::add($req, 'Accept');

        // Only register Accept-Charset if the client sent it and the chosen type cares
        if (
            $cset !== null
            && $req->getHeaderLine('Accept-Charset') !== ''
            && $this->charsetMattersFor($type)
        ) {
            VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
        }

        // 2) Accept-Language (locale)
        [$locale] = LocaleNegotiator::forRequest(
            $req,
            $this->locales ?: [$this->localeFallback],
            $this->localeFallback,
        );
        // Register Vary: Accept-Language now (Content-Language will be set later)
        VaryAccumulatorMiddleware::add($req, 'Accept-Language');

        // Stash choices for controllers
        $req = $req
            ->withAttribute('negotiated.type', $type)
            ->withAttribute('negotiated.charset', $cset)
            ->withAttribute('locale', $locale);

        // 3) Downstream
        $resp = $next($req);

        // 4) Ensure Content-Type and append charset when appropriate
        if (!in_array($resp->getStatusCode(), [204, 304], true)) {
            $existing = $resp->getHeaderLine('Content-Type');

            if ($existing === '') {
                $resp = $resp->withHeader('Content-Type', $this->composeContentType($type, $cset));
            } else {
                // If controller set a text/xml/js type without charset, and our negotiated charset exists, append it.
                $base = strtolower(strtok($existing, ';') ?: $existing);
                if (
                    stripos($existing, 'charset=') === false
                    && $cset !== null
                    && $this->charsetMattersFor($base)
                    && !$this->isJson($base)
                ) {
                    $resp = $resp->withHeader('Content-Type', trim($existing) . '; charset=' . $cset);
                }
            }
        }

        // 5) Always set Content-Language reflecting the chosen locale
        $resp = $resp->withHeader('Content-Language', $locale);

        return $resp;
    }

    /* ───────────────────────── helpers ───────────────────────── */

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
        $lower = strtolower($type);
        $hasParam = str_contains($type, ';');

        // never append for JSON (UTF-8 on the wire by spec)
        $needsCs = !$hasParam && !$this->isJson($lower) && (
                str_starts_with($lower, 'text/')
                || str_contains($lower, 'xml')
                || $lower === 'application/javascript'
                || $lower === 'text/javascript'
            );

        return $needsCs && $charset ? "{$type}; charset={$charset}" : $type;
    }

    /** True when charset changes octets on the wire for this media type. */
    private function charsetMattersFor(string $type): bool
    {
        $t = strtolower($type);
        if ($this->isJson($t)) return false;

        return str_starts_with($t, 'text/')
            || str_contains($t, 'xml')
            || $t === 'application/javascript'
            || $t === 'text/javascript';
    }

    /** Conservative check used for 406 short-circuit Vary decision. */
    private function charsetMattersForAny(array $types): bool
    {
        foreach ($types as $t) {
            if ($this->charsetMattersFor($t)) return true;
        }
        return false;
    }

    private function isJson(string $lowerType): bool
    {
        return str_starts_with($lowerType, 'application/json');
    }
}
