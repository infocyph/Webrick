<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Support\Etag;

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
 *   // (A) Full control – instance with custom provider
 *   new CacheValidatorsMiddleware(function(Request $r): array { return [$etag, $lastModified]; });
 *
 *   // (B) Class-string registration – set default once, then use ::class in stacks
 *   CacheValidatorsMiddleware::setDefaultMetaProvider(fn(Request $r) => [$etag, $lastModified]);
 *   // later in stack:
 *   CacheValidatorsMiddleware::class
 */
final class CacheValidatorsMiddleware
{
    /** @var null|Closure(Request): array{0:string|null,1:int|null} */
    private static ?Closure $defaultProvider = null;

    /** @param null|Closure(Request): array{0:string|null,1:int|null} $metaProvider */
    public function __construct(
        private ?Closure $metaProvider = null,
        private bool $autoEtagWhenMissing = true,
        private bool $includeQueryInEtag = true,
        private int $autoEtagMinSize = 2048,
    ) {}

    /**
     * Allow app code to set a global default provider once and still register via class-string.
     */
    public static function setDefaultMetaProvider(?Closure $provider): void
    {
        self::$defaultProvider = $provider;
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ── 1) Precondition evaluation (cheap, before controller) ───────── */
        [$etag, $lm] = $this->resolveProvider()($req);
        $validator = new ConditionalValidator($etag, $lm);
        $result = $validator->evaluate($req);

        if ($result->state !== Outcome::PASS) {
            // 304 / 412 with computed validator headers — controller won't run
            return Response::empty($result->http, $result->headers);
        }

        // If a Range was supplied but isn’t fresh, drop it so downstream generates 200.
        if ($req->hasHeader('Range') && !$validator->isRangeFresh($req)) {
            $req = $req->withoutHeader('Range')->withAttribute('range_dropped', true);
        }

        /* ── 2) Downstream ────────────────────────────────────────────────── */
        $resp = $next($req);

        /* ── 3) Ensure validators on final response ──────────────────────── */
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

            if (($computed = Etag::fromStream($resp->getBody(), $qs)) !== null) {
                $resp = $resp->withHeader('ETag', $computed);
            }
        }

        return $resp;
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** Choose provider in priority: ctor → global default → built-in fallback. */
    private function resolveProvider(): Closure
    {
        if ($this->metaProvider instanceof Closure) {
            return $this->metaProvider;
        }
        if (self::$defaultProvider instanceof Closure) {
            return self::$defaultProvider;
        }
        return self::fallbackMetaProvider(...);
    }

    /**
     * Built-in fallback: fast best-effort validators without I/O on bodies.
     * - If the requested path maps to a real file under DOCROOT, use its mtime/size.
     * - Otherwise, mint a stable-ish demo ETag salted by path + current script mtime.
     *
     * @return array{0:string|null,1:int|null} [ETag, Last-Modified]
     */
    private static function fallbackMetaProvider(Request $r): array
    {
        $path = $r->getUri()->getPath() ?: '/';
        $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $nowMtime = @filemtime((string)($_SERVER['SCRIPT_FILENAME'] ?? __FILE__)) ?: null;

        if ($docRoot !== '') {
            // 1) normalize request path: decode %XX and collapse dot-segments
            $decoded = rawurldecode($path);
            $norm = self::collapseDotSegments($decoded);
            $rel = ltrim($norm, "/\\");
            $candidate = $rel === '' ? $docRoot : ($docRoot . DIRECTORY_SEPARATOR . $rel);

            // 2) canonicalize with realpath() and bound to docroot
            $real = @realpath($candidate);
            if ($real !== false) {
                $rootNorm = self::normPath($docRoot) . '/';
                $realNorm = self::normPath($real);

                if (str_starts_with($realNorm . '/', $rootNorm) && @is_file($real) && @is_readable($real)) {
                    $size = @filesize($real) ?: 0;
                    $mtime = @filemtime($real) ?: $nowMtime;
                    $seed = $size . '|' . ($mtime ?? -1) . '|' . basename($real);
                    $etag = '"' . substr(sha1($seed), 0, 16) . '"';
                    return [$etag, $mtime];
                }
            }
        }

        // 3) fallback: synthetic, per-path (still revalidates fine client-side)
        $etag = '"' . substr(sha1('fallback|' . $path . '|' . (string)$nowMtime), 0, 16) . '"';
        return [$etag, $nowMtime];
    }

    /** RFC 3986-ish collapse of "." and ".." without touching leading slash semantics. */
    private static function collapseDotSegments(string $p): string
    {
        $isAbs = str_starts_with($p, '/');
        $parts = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        $out = implode('/', $parts);
        return $isAbs ? '/' . $out : $out;
    }

    /** Normalize for prefix checks: unify slashes, trim, and lower-case on Windows. */
    private static function normPath(string $p): string
    {
        $p = str_replace('\\', '/', $p);
        if (PHP_OS_FAMILY === 'Windows') {
            $p = strtolower($p);
        }
        return rtrim($p, '/');
    }

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
        return true;
    }
}
