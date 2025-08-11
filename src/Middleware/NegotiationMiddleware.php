<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Negotiation\LocaleNegotiator;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

final class NegotiationMiddleware
{
    /** @param string[] $produces  e.g. ['application/json','text/html']
     *  @param string[] $charsets  e.g. ['utf-8','iso-8859-1']
     *  @param string[] $locales   ordered by server preference, most → least
     */
    public function __construct(
        private array $produces = ['application/json', 'text/html'],
        private array $charsets = ['utf-8'],
        private array $locales = ['en'],
        private string $localeFallback = 'en',
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ── route-level overrides (optional) ─────────────────────────── */
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

        /* ── 1) negotiate media type + charset from request headers ───── */
        $neg = new ContentNegotiator($req->headers());
        $type = $neg->preferred($prod); // null ⇒ 406

        if ($type === null) {
            // Vary will still be finalized by VaryAccumulatorMiddleware
            return new Response(
                status: 406,
                headers: ['Content-Type' => 'text/plain; charset=utf-8'],
                body: new Stream('Not acceptable.'),
            );
        }

        $charset = $this->pickCharset($neg, $char); // may be null

        // Register Vary: Accept; Accept-Charset is conditional (only when it matters)
        VaryAccumulatorMiddleware::add($req, 'Accept');
        if (
            $charset !== null
            && $req->getHeaderLine('Accept-Charset') !== ''
            && $this->charsetMattersFor($type)
        ) {
            VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
        }

        /* ── 2) negotiate locale (always with fallback) ───────────────── */
        [$locale] = LocaleNegotiator::forRequest(
            $req,
            $this->locales ?: [$this->localeFallback],
            $this->localeFallback,
        );
        $req = $req->withAttribute('locale', $locale);
        VaryAccumulatorMiddleware::add($req, 'Accept-Language');

        // Stash negotiated results for controllers
        $req = $req
            ->withAttribute('negotiated.type', $type)
            ->withAttribute('negotiated.charset', $charset);

        /* ── 3) downstream ────────────────────────────────────────────── */
        $resp = $next($req);

        /* ── 4) finalize headers on the response ──────────────────────── */

        // Always surface the negotiated language
        $resp = $resp->withHeader('Content-Language', $locale);

        // If controller didn’t set Content-Type, apply negotiated one (skip no-body)
        if (
            !$resp->hasHeader('Content-Type')
            && !in_array($resp->getStatusCode(), [204, 304], true)
        ) {
            $resp = $resp->withHeader('Content-Type', $this->composeContentType($type, $charset));
            return $resp; // done
        }

        // If controller DID set Content-Type but forgot charset for textual types, attach it.
        $ctype = $resp->getHeaderLine('Content-Type');
        if (
            $ctype !== ''
            && stripos($ctype, 'charset=') === false
            && !$this->isJson($ctype)
            && $this->charsetTokenShouldAppear($ctype)
            && $charset !== null
        ) {
            $resp = $resp->withHeader('Content-Type', rtrim($ctype) . '; charset=' . $charset);
        }

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
        $needsCs = !$hasParam && !$this->isJson($lower) && $this->charsetMattersFor($lower);

        return $needsCs && $charset ? "{$type}; charset={$charset}" : $type;
    }

    private function charsetMattersFor(string $type): bool
    {
        $t = strtolower($type);
        if ($this->isJson($t)) {
            return false; // JSON is UTF-8 on the wire by spec
        }
        return str_starts_with($t, 'text/')
            || str_contains($t, 'xml')
            || $t === 'application/javascript'
            || $t === 'text/javascript';
    }

    private function charsetTokenShouldAppear(string $ctype): bool
    {
        $t = strtolower(strtok($ctype, ';') ?: $ctype);
        return $this->charsetMattersFor($t);
    }

    private function isJson(string $type): bool
    {
        $t = strtolower($type);
        return str_starts_with($t, 'application/json');
    }
}
