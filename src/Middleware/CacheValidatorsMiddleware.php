<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;

/**
 * CacheValidatorsMiddleware
 *
 * Responsibilities:
 *  • Evaluate If-* preconditions (ETag / Last-Modified / Range freshness) before controller work.
 *  • Short-circuit with 304 / 412 when possible.
 *  • Strip stale Range so downstream returns 200 with full body.
 *  • Ensure final response carries validators (ETag / Last-Modified) if the controller omitted them.
 *  • Optionally auto-generate a strong ETag from the response body using chunked hashing.
 *
 * Usage:
 *   $mw = new CacheValidatorsMiddleware(function(Request $r): array {
 *       // Return current validators for the route/entity (fast! no body I/O)
 *       // etag as string (quoted or unquoted – we’ll normalize), last-modified as UNIX timestamp
 *       return [$etag, $lastModified];
 *   });
 *
 * Order:
 *   Negotiation → CacheValidators → (controller) → Compression → VaryAccumulator
*
 * Notes:
 *   • If Compression later adds Content-Encoding, it should weaken a strong ETag (your code already does).
 *   • Auto-ETag is only attempted when: status=200, body is seekable, and no ETag header exists.
 */
final class CacheValidatorsMiddleware
{
    /**
     * @param Closure(Request): array{0:string|null,1:int|null} $metaProvider
     * @param bool $autoEtagWhenMissing  Compute strong ETag from body if still missing after controller
     * @param bool $includeQueryInEtag   Salt auto ETag with normalized query-string
     * @param int  $autoEtagMinSize      Don’t hash tiny bodies (bytes); 0 = always
     */
    public function __construct(
        private readonly Closure $metaProvider,
        private readonly bool $autoEtagWhenMissing = true,
        private readonly bool $includeQueryInEtag = true,
        private readonly int $autoEtagMinSize = 0,
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ── 1) Precondition evaluation (cheap, before controller) ───────── */
        [$etag, $lm] = ($this->metaProvider)($req);
        $validator = new ConditionalValidator($etag, $lm);
        $result = $validator->evaluate($req);

        if ($result->state !== Outcome::PASS) {
            // 304 / 412 with the computed validator headers
            return Response::empty($result->http, $result->headers);
        }

        // If a Range was supplied but isn’t fresh, drop it so downstream generates 200.
        if ($req->hasHeader('Range') && !$validator->isRangeFresh($req)) {
            $req = $req
                ->withoutHeader('Range')
                ->withAttribute('range_dropped', true);
        }

        /* ── 2) Downstream ────────────────────────────────────────────────── */
        $resp = $next($req);

        /* ── 3) Ensure validators are present on the final response ───────── */
        // Add the precomputed headers (ETag / Last-Modified) only if missing.
        foreach ($result->headers as $h => $v) {
            if ($v !== null && !$resp->hasHeader($h)) {
                $resp = $resp->withHeader($h, $v);
            }
        }

        // Optionally auto-ETag when still missing
        if (
            $this->autoEtagWhenMissing
            && !$resp->hasHeader('ETag')
            && $this->isAutoEtagEligible($resp)
        ) {
            $qs = $this->includeQueryInEtag
                ? Uri::normalizeQueryString($req->getUri()->getQuery())
                : '';

            if (($computed = $this->computeStrongEtag($resp, $qs)) !== null) {
                $resp = $resp->withHeader('ETag', $computed);
            }
        }

        return $resp;
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function isAutoEtagEligible(Response $r): bool
    {
        if ($r->getStatusCode() !== 200) {
            return false;
        }
        $b = $r->getBody();
        if (!$b->isSeekable()) {
            return false;
        }
        $size = $b->getSize();
        if ($size !== null && $size < $this->autoEtagMinSize) {
            return false;
        }
        // If the controller already compressed (rare), we’ll hash the on-the-wire bytes, which is fine.
        return true;
    }

    /**
     * Compute a strong ETag from the response body using chunked hashing.
     * We return a quoted hex (first 16 of SHA-1) to keep tags short and cache-friendly.
     *
     * @return string|null  e.g. "\"a1b2c3d4e5f67890\"" or null on failure
     */
    private function computeStrongEtag(Response $r, string $qsSalt = ''): ?string
    {
        $body = $r->getBody();
        if (!$body->isSeekable()) {
            return null;
        }

        try {
            $pos = $body->tell();
            $body->seek(0);

            $ctx = hash_init('sha1');
            if ($qsSalt !== '') {
                hash_update($ctx, $qsSalt . "\n");
            }

            // stream in chunks (no giant string casts)
            while (!$body->eof()) {
                $chunk = $body->read(131072); // 128 KiB
                if ($chunk === '') {
                    break;
                }
                hash_update($ctx, $chunk);
            }

            $hex = hash_final($ctx);
            // short strong tag: sha1/16
            $tag = '"' . substr($hex, 0, 16) . '"';

            // restore pointer
            $body->seek($pos);

            return $tag;
        } catch (\Throwable) {
            // best-effort: don’t break responses if hashing fails
            return null;
        }
    }
}
