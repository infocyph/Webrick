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

    /**
     * Creates a new CacheValidatorsMiddleware instance.
     *
     * @param null|Closure(Request): array{0:string|null,1:int|null} $metaProvider The meta provider to use, or null to use the default meta provider.
     * @param bool $autoEtagWhenMissing Whether to auto-generate a strong ETag from the response body using chunked hashing when the response does not already have an ETag.
     * @param bool $includeQueryInEtag Whether to include the request query string in the auto-generated ETag.
     * @param int $autoEtagMinSize The minimum response body size to auto-generate an ETag for.
     */
    public function __construct(
        private readonly ?Closure $metaProvider = null,
        private readonly bool $autoEtagWhenMissing = true,
        private readonly bool $includeQueryInEtag = true,
        private readonly int $autoEtagMinSize = 2048,
    ) {
    }

    /**
     * Sets the default meta provider for all CacheValidatorsMiddleware instances.
     *
     * If no meta provider is explicitly passed to the CacheValidatorsMiddleware constructor,
     * the default meta provider is used instead.
     *
     * The default meta provider should be a Closure instance that takes a Request object as its
     * only argument and returns an array containing the ETag and Last-Modified values for the request.
     *
     * @param null|Closure(Request): array{0:string|null,1:int|null} $provider The default meta provider to use
     */
    public static function setDefaultMetaProvider(?Closure $provider): void
    {
        self::$defaultProvider = $provider;
    }

    /**
     * Handle a request and return a response.
     *
     * This method is the entry point of the middleware.
     * It evaluates preconditions, short-circuits on 304/412, drops stale Range headers,
     * calls the next middleware in the stack, and finally ensures validators are present in the response,
     * optionally auto-generating a strong ETag for GET/HEAD responses.
     *
     * @param Request $req The request to process
     * @param Closure $next The next middleware to call
     * @return Response The response to return
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

        // 5) Post: ensure validators + maybe auto-ETag (GET/HEAD only)
        if ($isGetHead) {
            $resp = $this->ensureValidatorHeaders($resp, $result->headers ?? []);
            $resp = $this->maybeAttachAutoEtag($resp, $req);
        }

        return $resp;
    }

    /**
     * Resolve the meta provider instance to use for evaluating preconditions.
     *
     * This method returns a Closure instance, which is either:
     *  - The instance's meta provider if set
     *  - The global default meta provider if set
     *  - The built-in fallback meta provider otherwise
     *
     * @return Closure The meta provider instance to use
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

    /**
     * Check if the given request method is GET or HEAD.
     *
     * @param Request $req The request to check
     * @return bool True if the request method is GET or HEAD, false otherwise
     */
    private function isGetOrHead(Request $req): bool
    {
        $m = strtoupper($req->getMethod());
        return $m === 'GET' || $m === 'HEAD';
    }

    /**
     * Evaluate If-* preconditions using the resolved meta provider.
     *
     * Resolves the meta provider, extracts the ETag and Last-Modified from it,
     * creates a ConditionalValidator instance with those values, and
     * evaluates the preconditions against the given request.
     *
     * Returns an array containing the ConditionalValidator instance and the
     * result of the evaluation.
     *
     * @param Request $req The request to evaluate preconditions against
     * @return array [$validator, $result] The ConditionalValidator instance and the result of the evaluation
     */
    private function evaluatePreconditionsWithProvider(Request $req): array
    {
        [$etag, $lm] = $this->resolveProvider()($req);
        $validator = new ConditionalValidator($etag, $lm);
        $result = $validator->evaluate($req);
        return [$validator, $result];
    }

    /**
     * Short-circuit response if preconditions fail (e.g., 304 or 412).
     *
     * If the preconditions fail (i.e., the outcome is not Outcome::PASS), returns a short-circuit response with the computed HTTP status and headers.
     * Otherwise, returns null.
     *
     * RFC 7232 dictates that non-GET/HEAD requests with If-None-Match preconditions should return a 412 status code instead of a 304.
     * @param object $result Has ->state, ->http, ->headers
     * @param bool $isGetHead Is the request a GET or HEAD?
     * @return Response|null Short-circuit response if preconditions fail, otherwise null
     */
    private function maybeShortCircuit(object $result, bool $isGetHead): ?Response
    {
        if (($result->state ?? null) === Outcome::PASS) {
            return null;
        }

        // RFC 7232: Non-GET/HEAD with If-None-Match → 412 instead of 304
        $status = (!$isGetHead && ($result->http ?? 0) === 304) ? 412 : ($result->http ?? 412);
        return Response::empty($status, $result->headers ?? []);
    }

    /**
     * Maybe drop the Range header from the request if the Range is stale.
     *
     * This method is only relevant for GET/HEAD requests with a Range header.
     * If the Range is stale, the Range header is dropped from the request.
     * Otherwise, the original request is returned.
     *
     * @param Request $req The request to check and modify if necessary.
     * @param ConditionalValidator $validator The validator to use for checking the freshness of the Range.
     * @param bool $isGetHead Whether the request is a GET or HEAD request.
     * @return Request The modified request if the Range is stale, otherwise the original request.
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
     * Ensure the response has the specified validator headers.
     *
     * If any of the specified validator headers are missing from the response, adds them to the response.
     * @param Response $resp The response to modify
     * @param array<string, string|null> $headers The validator headers to ensure are present in the response, keyed by header name
     * @return Response The modified response with the specified validator headers added if missing
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
     * Maybe attach an auto-computed ETag to the response.
     *
     * Conditions to attach an auto-ETag:
     *  - `autoEtagWhenMissing` is true
     *  - The response does not already have an ETag
     *  - The response is eligible for auto-ETag computation (i.e., its body is seekable and >= $autoEtagMinSize bytes)
     *
     * If the conditions are met, computes an ETag from the response body, optionally salted by the request query string.
     * @param Response $resp The response to maybe attach an auto-ETag to
     * @param Request $req The original request
     * @return Response The response with an auto-ETag attached if conditions are met, otherwise the original response
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
     * Built-in fallback: fast best-effort validators without I/O on bodies.
     * - If the requested path maps to a real file under DOCROOT, use its mtime/size.
     * - Otherwise, mint a stable-ish demo ETag salted by path + current script mtime.
     *
     * @return array{0:string|null,1:int|null} [ETag, Last-Modified]
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

        // Synthetic but stable-enough per-path fallback
        return [self::syntheticEtag($path, $scriptMts), $scriptMts];
    }

    /**
     * DOCUMENT_ROOT if set, else null.
     *
     * @return string|null DOCUMENT_ROOT if set, else null
     */
    private static function docRoot(): ?string
    {
        $dr = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        return $dr !== '' ? $dr : null;
    }

    /**
     * Current script's mtime (file modification time) or null if not accessible.
     *
     * @return int|null File modification time in seconds since epoch, or null if not accessible.
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
     * Resolve request path to a safe, readable file under docroot.
     *
     * Given a docroot and a path, returns an array containing the real file path,
     * file size, and file mtime if the file is readable and exists under docroot.
     * Otherwise, returns null.
     *
     * This function is more strict than the standard realpath() function, as it
     * checks that the resolved file is under the docroot and is a file.
     *
     * @param string $docRoot The document root directory
     * @param string $path The request path to resolve
     * @return array{0:string,1:int,2:int}|null [$realPath, $size, $mtime]
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
     * Build a short ETag fingerprint from file facts.
     *
     * Given file size, mtime, and path, returns a quoted hex (first 16 chars of xxh3 digest).
     * @param int $size File size in bytes
     * @param int $mtime File mtime in seconds since Epoch
     * @param string $realPath Absolute file path
     * @return string Quoted hex ETag fingerprint
     */
    private static function etagForFile(int $size, int $mtime, string $realPath): string
    {
        $seed = $size . '|' . $mtime . '|' . basename($realPath);
        return '"' . substr(hash('xxh3', $seed, false), 0, 16) . '"';
    }

    /**
     * Builds a synthetic ETag fingerprint based on the request path and an optional best-effort script mtime.
     *
     * This is a fallback for when we can't determine a strong ETag (e.g., no file under docroot).
     *
     * @param string $path The request path
     * @param int|null $scriptMtime An optional best-effort mtime of the script (for better cache key stability)
     * @return string A quoted, 16-char ETag fingerprint (hex)
     */
    private static function syntheticEtag(string $path, ?int $scriptMtime): string
    {
        return '"' . substr(hash('xxh3', 'fallback|' . $path . '|' . (string)$scriptMtime, false), 0, 16) . '"';
    }

    /**
     * Collapse dot segments (RFC 3986-ish) without touching leading slash semantics.
     *
     * This function takes a path string and collapses all occurrences of "." and ".."
     * while preserving absolute path semantics. "." is ignored, ".." removes the
     * last segment, and trailing slashes are removed.
     *
     * @param string $p The path string to collapse
     * @return string The collapsed path string
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
     * Normalizes a path by replacing backslashes with forward slashes and
     * (on Windows) converting to lowercase. Finally, trims any trailing
     * slashes from the end of the path.
     *
     * @param string $p The path to normalize
     * @return string The normalized path
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
     * Determines if the given response is eligible for auto ETag generation.
     *
     * Auto ETag is generated if the response has a 200 status code,
     * the body is seekable (i.e., it supports tell() and rewind()),
     * and the body size is greater than or equal to the configured min size.
     *
     * @param Response $r The response to check
     * @return bool True if the response is eligible for auto ETag generation, false otherwise
     */
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
        return !($size !== null && $size < $this->autoEtagMinSize);
    }
}
