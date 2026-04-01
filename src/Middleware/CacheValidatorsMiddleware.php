<?php

declare(strict_types=1);

/**
 * HTTP caching middleware that evaluates request preconditions (ETag/Last-Modified)
 * and manages validator headers and auto-ETag generation for GET/HEAD requests.
 *
 * Responsibilities:
 * - Evaluate conditional headers via `ConditionalValidator`.
 * - Short-circuit responses with 304/412 where applicable.
 * - Drop stale Range headers when validators indicate staleness.
 * - Ensure validator headers are present on successful responses.
 * - Optionally compute and attach an automatic strong ETag based on response body.
 *
 * Notes:
 * - File-system metadata may be used when a document root is available; otherwise a synthetic ETag is generated.
 * - Personalization marker (`Request` attribute `personalized`) makes responses private and not publicly cacheable.
 */

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\Etag;

/**
 * Middleware that applies HTTP conditional request handling and caching validators.
 *
 * This middleware:
 * - Computes validator metadata (ETag and Last-Modified) using a provided or default provider.
 * - Evaluates RFC 7232 preconditions to possibly short-circuit with 304 or 412.
 * - Drops stale Range headers for GET/HEAD requests.
 * - Ensures validator headers exist on responses and can auto-generate ETags.
 *
 * It is intended for idempotent endpoints (primarily GET/HEAD). Non-GET/HEAD with If-None-Match
 * may yield 412 as per RFC 7232.
 */
final class CacheValidatorsMiddleware
{
    /**
     * Default metadata provider used when no instance-level provider is supplied.
     *
     * Provider signature: `Closure(Request): array{0: string|null, 1: int|null}`
     * - Return tuple: [etag, lastModifiedTimestamp]
     * - Use `null` where a component is not available.
     *
     * @var null|Closure(Request): array{0:string|null,1:int|null}
     */
    private static ?Closure $defaultProvider = null;

    /**
     * @param null|Closure(Request): array{0:string|null,1:int|null} $metaProvider Provider for [etag, lastModified]; falls back when null
     * @param bool $autoEtagWhenMissing Whether to auto-compute an ETag when upstream did not set one
     * @param bool $includeQueryInEtag Whether query string participates in auto-ETag computation
     * @param int $autoEtagMinSize Minimum body size (bytes) to be eligible for auto-ETag
     */
    public function __construct(
        private readonly ?Closure $metaProvider = null,
        private readonly bool $autoEtagWhenMissing = true,
        private readonly bool $includeQueryInEtag = true,
        private readonly int $autoEtagMinSize = 2048,
    ) {
    }

    /**
     * Handle the request, applying conditional logic and validator management.
     *
     * Steps:
     * 1) Compute and evaluate preconditions via a validator provider.
     * 2) Short-circuit for 304/412 as needed.
     * 3) Drop stale Range headers on GET/HEAD.
     * 4) Delegate to downstream.
     * 5) Ensure validators and possibly attach an auto-ETag for GET/HEAD.
     *
     * Side effects:
     * - May mutate request headers (drop Range).
     * - May mutate response headers (cache-control, ETag, validator headers).
     *
     * @return Response Final response (possibly short-circuited)
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        $isGetHead = $this->isGetOrHead($req);

        // 1) Preconditions: compute + evaluate
        [$validator, $result] = $this->evaluatePreconditionsWithProvider($req);

        // 2) Early exit on 304/412
        if ($resp = $this->maybeShortCircuit($result, $isGetHead)) {
            return $resp;
        }

        // 3) Drop stale Range for GET/HEAD
        $req = $this->maybeDropStaleRangeHeader($req, $validator, $isGetHead);

        // 4) Downstream
        $resp = $next($req);

        // 4.5) If upstream marked the request as personalized (e.g., locale from cookie),
        //      ensure the response is not publicly cacheable. Do NOT add Vary: Cookie.
        if ($req->getAttribute('personalized')) {
            $resp = $resp->withCache(fn ($cc) => $cc->private()->noTransform());
        }

        // 5) Post: ensure validators + maybe auto-ETag (GET/HEAD only)
        if ($isGetHead) {
            $resp = $this->ensureValidatorHeaders($resp, $result->headers ?? []);
            $resp = $this->maybeAttachAutoEtag($resp, $req);
        }

        return $resp;
    }

    /**
     * Set a process-wide default metadata provider.
     *
     * @param null|Closure(Request): array{0:string|null,1:int|null} $provider
     *   Provider returning [etag, lastModifiedTimestamp]
     */
    public static function setDefaultMetaProvider(?Closure $provider): void
    {
        self::$defaultProvider = $provider;
    }

    /**
     * Collapse "." and ".." path segments as per RFC 3986 Section 5.2.4.
     *
     * @return string Normalized path, preserving leading slash for absolute paths
     */
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

    /**
     * Get the server document root if available.
     *
     * @return string|null Absolute path to document root or null if unspecified
     */
    private static function docRoot(): ?string
    {
        $dr = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        return $dr !== '' ? $dr : null;
    }

    /**
     * Build a deterministic strong ETag for a file based on size, mtime, and basename.
     *
     * @param int $size File size in bytes
     * @param int $mtime File modification time (unix timestamp)
     * @param string $realPath Real path to the file
     * @return string ETag value including quotes
     */
    private static function etagForFile(int $size, int $mtime, string $realPath): string
    {
        $seed = $size . '|' . $mtime . '|' . basename($realPath);
        return '"' . substr(hash('xxh3', $seed, false), 0, 16) . '"';
    }

    /**
     * Default metadata provider: derive validators from the filesystem under docroot,
     * falling back to a synthetic ETag when unavailable.
     *
     * @return array{0:string|null,1:int|null} [etag, lastModifiedTimestamp]
     */
    private static function fallbackMetaProvider(Request $r): array
    {
        $path = $r->getUri()->getPath() ?: '/';
        $docRoot = self::docRoot();
        $scriptMts = self::scriptMtime();

        if ($docRoot !== null) {
            if ($info = self::resolveFileUnderDocroot($docRoot, $path)) {
                [$real, $size, $mtime] = $info;
                $etag = self::etagForFile($size, $mtime, $real);
                return [$etag, $mtime];
            }
        }

        return [self::syntheticEtag($path, $scriptMts), $scriptMts];
    }

    /**
     * Normalize a filesystem path for cross-platform comparisons.
     *
     * - Converts separators to forward slashes.
     * - Lowercases on Windows.
     * - Trims trailing slashes.
     */
    private static function normPath(string $p): string
    {
        $p = str_replace('\\', '/', $p);
        if (PHP_OS_FAMILY === 'Windows') {
            $p = strtolower($p);
        }
        return rtrim($p, '/');
    }

    /**
     * Resolve a requested path to a readable file under the given document root.
     *
     * Security:
     * - Decodes percent-encoding and collapses dot segments to prevent traversal.
     * - Verifies the resolved realpath remains within the document root.
     *
     * @return array{0:string,1:int,2:int}|null [realpath, size, mtime] or null if not resolvable
     */
    private static function resolveFileUnderDocroot(string $docRoot, string $path): ?array
    {
        $decoded = rawurldecode($path);
        $norm = self::collapseDotSegments($decoded);
        $rel = ltrim($norm, "/\\");
        $cand = $rel === '' ? $docRoot : ($docRoot . DIRECTORY_SEPARATOR . $rel);

        $real = realpath($cand);
        if ($real === false) {
            return null;
        }

        $rootNorm = self::normPath($docRoot) . '/';
        $realNorm = self::normPath($real) . '/';
        $isUnder = str_starts_with($realNorm, $rootNorm);

        if (!$isUnder || !is_file($real) || !is_readable($real)) {
            return null;
        }

        $size = filesize($real);
        $mtime = filemtime($real);
        if ($size === false || $mtime === false) {
            return null;
        }

        return [$real, (int)$size, (int)$mtime];
    }

    /**
     * Determine the current script's modification time if available.
     *
     * @return int|null Unix timestamp or null when not accessible
     */
    private static function scriptMtime(): ?int
    {
        $file = (string)($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
        if (!is_file($file)) {
            return null;
        }
        $mt = filemtime($file);
        return $mt === false ? null : $mt;
    }

    /**
     * Produce a stable synthetic ETag when no file metadata is available.
     *
     * @param string $path Request path (raw)
     * @param int|null $scriptMtime Script mtime for additional entropy
     * @return string ETag value including quotes
     */
    private static function syntheticEtag(string $path, ?int $scriptMtime): string
    {
        return '"' . substr(hash('xxh3', 'fallback|' . $path . '|' . (string)$scriptMtime, false), 0, 16) . '"';
    }

    /**
     * Ensure the provided validator headers exist on the response without overwriting existing ones.
     *
     * @param array<string,string> $headers Header map to ensure (e.g., ETag, Last-Modified)
     */
    private function ensureValidatorHeaders(Response $resp, array $headers): Response
    {
        foreach ($headers as $h => $v) {
            if ($v !== null && !$resp->hasHeader($h)) {
                $resp = $resp->withHeader($h, $v);
            }
        }
        return $resp;
    }

    /**
     * Compute validator metadata using the resolved provider and evaluate request preconditions.
     *
     * @return array{0:ConditionalValidator,1:object} [validator, evaluationResult]
     */
    private function evaluatePreconditionsWithProvider(Request $req): array
    {
        [$etag, $lm] = $this->resolveProvider()($req);
        $validator = new ConditionalValidator($etag, $lm);
        $result = $validator->evaluate($req);
        return [$validator, $result];
    }

    /**
     * Check if a response is eligible for auto-ETag generation.
     *
     * Conditions:
     * - Status code must be 200.
     * - Body must be seekable.
     * - Body size, if known, must meet the minimum threshold.
     */
    private function isAutoEtagEligible(Response $r): bool
    {
        if ($r->getStatusCode() !== StatusEnum::OK->value) {
            return false;
        }
        $b = $r->getBody();
        if (!$b->isSeekable()) {
            return false;
        }
        $size = $b->getSize();
        return !($size !== null && $size < $this->autoEtagMinSize);
    }

    /**
     * Determine whether the request method is GET or HEAD.
     */
    private function isGetOrHead(Request $req): bool
    {
        $m = HttpMethodEnum::normalize($req->getMethod());
        return $m === HttpMethodEnum::GET->value || $m === HttpMethodEnum::HEAD->value;
    }

    /**
     * Attach an auto-generated ETag when missing and eligible.
     *
     * Uses the normalized query string as part of the ETag seed when configured.
     */
    private function maybeAttachAutoEtag(Response $resp, Request $req): Response
    {
        if (
            !$this->autoEtagWhenMissing
            || $resp->hasHeader('ETag')
            || !$this->isAutoEtagEligible($resp)
        ) {
            return $resp;
        }

        $qs = $this->includeQueryInEtag
            ? Uri::normalizeQueryString($req->getUri()->getQuery())
            : '';

        if (($computed = Etag::fromStream($resp->getBody(), $qs)) !== null) {
            $resp = $resp->withHeader('ETag', $computed);
        }
        return $resp;
    }

    /**
     * Remove a stale Range header for GET/HEAD requests when validators indicate staleness.
     *
     * Marks the request with attribute `range_dropped` when the header is removed.
     */
    private function maybeDropStaleRangeHeader(Request $req, ConditionalValidator $validator, bool $isGetHead): Request
    {
        if (!$isGetHead || !$req->hasHeader('Range')) {
            return $req;
        }
        return $validator->isRangeFresh($req)
            ? $req
            : $req->withoutHeader('Range')->withAttribute('range_dropped', true);
    }

    /**
     * Convert a precondition evaluation result to a short-circuit response when applicable.
     *
     * RFC 7232 rule:
     * - Non-GET/HEAD with If-None-Match should yield 412 instead of 304.
     *
     * @return Response|null 304/412 response or null to continue pipeline
     */
    private function maybeShortCircuit(object $result, bool $isGetHead): ?Response
    {
        if (($result->state ?? null) === Outcome::PASS) {
            return null;
        }

        // RFC 7232: Non-GET/HEAD with If-None-Match → 412 instead of 304
        $status = (!$isGetHead && ($result->http ?? 0) === StatusEnum::NOT_MODIFIED->value)
            ? StatusEnum::PRECONDITION_FAILED->value
            : ($result->http ?? StatusEnum::PRECONDITION_FAILED->value);
        return Response::empty($status, $result->headers ?? []);
    }

    /**
     * Resolve the active metadata provider (instance-level, static default, or fallback).
     *
     * @return Closure(Request): array{0:string|null,1:int|null}
     */
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
}
