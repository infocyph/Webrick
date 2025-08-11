<?php

// src/Middleware/ContentNegotiationMiddleware.php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

final class ContentNegotiationMiddleware
{
    /** @param string[] $produces e.g. ['application/json','text/html'] */
    /** @param string[] $charsets e.g. ['utf-8','iso-8859-1'] */
    public function __construct(
        private array $produces = ['application/json', 'text/html'],
        private array $charsets = ['utf-8'],
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        // Route-specific override via attribute (optional)
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

        // 1) Negotiate from the request headers
        $neg = new ContentNegotiator($req->headers());
        $type = $neg->preferred($prod);                  // null ⇒ 406
        $cset = $this->pickCharset($neg, $char);         // may be null

        if ($type === null) {
            // 406 Not Acceptable (Vary will still be finalized by VaryAccumulatorMiddleware)
            return new Response(
                status: 406,
                headers: ['Content-Type' => 'text/plain; charset=utf-8'],
                body: new Stream('Not acceptable.'),
            );
        }

        // Register Vary for what we actually negotiated
        VaryAccumulatorMiddleware::add($req, 'Accept');

        // Only register Accept-Charset if (a) client sent it and (b) charset affects bytes
        if (
            $cset !== null
            && $req->getHeaderLine('Accept-Charset') !== ''
            && $this->charsetMattersFor($type)
        ) {
            VaryAccumulatorMiddleware::add($req, 'Accept-Charset');
        }

        // Stash for controllers (if they want to see what was chosen)
        $req = $req
            ->withAttribute('negotiated.type', $type)
            ->withAttribute('negotiated.charset', $cset);

        // 2) Downstream
        $resp = $next($req);

        // 3) If controller didn’t set Content-Type, apply our negotiated one
        if (
            !$resp->hasHeader('Content-Type') &&
            !in_array($resp->getStatusCode(), [204, 304], true) // no body statuses
        ) {
            $resp = $resp->withHeader('Content-Type', $this->composeContentType($type, $cset));
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

        // Do NOT append charset for JSON
        $isJson = str_starts_with($lower, 'application/json');

        // Append for text/*, *xml*, and JavaScript types when no existing params
        $needsCs = !$hasParam && !$isJson && (
                str_starts_with($lower, 'text/')
                || str_contains($lower, 'xml')
                || $lower === 'application/javascript'
                || $lower === 'text/javascript'
            );

        return $needsCs && $charset ? "{$type}; charset={$charset}" : $type;
    }

    /** True when charset changes the actual octets on the wire for this type. */
    private function charsetMattersFor(string $type): bool
    {
        $t = strtolower($type);

        // JSON is always UTF-8 by spec on the wire; don't vary on charset.
        if (str_starts_with($t, 'application/json')) {
            return false;
        }

        return str_starts_with($t, 'text/')
            || str_contains($t, 'xml')
            || $t === 'application/javascript'
            || $t === 'text/javascript';
    }
}
