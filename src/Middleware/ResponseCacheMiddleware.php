<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

/**
 * Tiny server-side micro-cache for idempotent responses.
 *
 * Goals:
 *  - Cache GET/HEAD only (safe methods).
 *  - Key = method + host + path (+ optional query) + negotiated/vary surface.
 *  - Skip caching when request/response implies personalization or no-store.
 *  - Respect small TTL (micro-cache) and s-maxage/max-age caps.
 *  - Avoid streaming responses and oversized bodies.
 *
 * Works best when:
 *  - NegotiationMiddleware + VaryAccumulatorMiddleware run BEFORE this.
 *  - CacheValidatorsMiddleware runs AFTER (this cache stores final validators too).
 */
final readonly class ResponseCacheMiddleware
{
    /** Cache store (PSR-16-style wrapper) */
    private CacheInterface $store;

    /**
     * @param CacheInterface|null $store Cache backend; defaults to local('http')
     * @param int $ttlSeconds Base TTL for cache entries (e.g., 10)
     * @param bool $includeQuery Whether to include normalized query in the cache key
     * @param int $maxBodyBytes Upper bound for payload size to cache
     * @param string[] $defaultVary Headers to consider when no vary accumulator present
     * @param bool $skipWhenPersonalized Skip caching if $req->getAttribute('personalized')
     * @param bool $respectResponseCacheControl Honor no-store/private and (s-)maxage caps
     * @param bool $avoidSetCookie Do not cache responses that set cookies
     */
    public function __construct(
        ?CacheInterface $store = null,
        private int $ttlSeconds = 10,
        private bool $includeQuery = true,
        private int $maxBodyBytes = 1_048_576, // 1 MiB
        private array $defaultVary = ['Accept', 'Accept-Language', 'Accept-Encoding'],
        private bool $skipWhenPersonalized = true,
        private bool $respectResponseCacheControl = true,
        private bool $avoidSetCookie = true,
    ) {
        $this->store = $store ?? $this->buildDefaultStore();
    }

    /**
     * @param Closure(Request):Response $next
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        // Cache only safe, idempotent reads.
        $method = HttpMethodEnum::normalize($req->getMethod());
        $isHead = $method === HttpMethodEnum::HEAD->value;
        if (!$this->isCacheMethod($method) || $this->isPersonalizedRequest($req)) {
            return $this->invokeNext($next, $req);
        }

        // Build key: method + host + path (+query?) + vary surface (+ negotiated attrs).
        $key = $this->makeKey($req);

        // Fast path: serve from cache when available.
        $hit = $this->store->get($key);
        if (\is_array($hit)) {
            $resp = $this->unpack($hit);

            // HEAD should not include a body.
            if ($isHead) {
                $resp = $resp
                    ->withBody(new Stream(''))
                    ->withSmartHeader('Content-Length', $resp->getHeaderLine('Content-Length') ?: '0');
            }

            return $resp;
        }

        // Miss → fall through.
        $resp = $this->invokeNext($next, $req);

        // Decide whether to store.
        if ($this->isCacheable($resp)) {
            $ttl = $this->computeTtl($resp);
            if ($ttl > 0) {
                $this->store->set($key, $this->pack($resp), $ttl);
            }
        }

        return $resp;
    }

    /**
     * @param array<string,string> $pairs
     * @return array<string,string>
     */
    private function appendVaryTokens(array $pairs, Request $req, mixed $tokens): array
    {
        if (!\is_array($tokens) || $tokens === []) {
            return $pairs;
        }

        foreach ($tokens as $token) {
            if (!\is_string($token)) {
                continue;
            }
            $name = $this->canonicalHeaderToken($token);
            if ($name === '') {
                continue;
            }
            // Keep empty values too (absence is a valid cache variant).
            $pairs[$name] = $req->getHeaderLine($name);
        }

        return $pairs;
    }

    private function buildDefaultStore(): CacheInterface
    {
        // Windows reports directory modes differently than POSIX; prefer memory cache without APCu.
        if (\PHP_OS_FAMILY === 'Windows' && !\extension_loaded('apcu')) {
            return Cache::memory('http');
        }

        return Cache::local('http');
    }

    /**
     * Canonicalize a header token to Title-Case for deterministic keying.
     */
    private function canonicalHeaderToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        return implode(
            '-',
            array_map(
                static fn(string $part): string => $part === '' ? '' : ucfirst(strtolower($part)),
                explode('-', $token),
            ),
        );
    }

    private function computeTtl(Response $resp): int
    {
        $ttl = max(0, $this->ttlSeconds);
        if (!$this->respectResponseCacheControl) {
            return $ttl;
        }

        $cc = $this->parseCacheControl($resp->getHeaderLine('Cache-Control'));

        // Prefer s-maxage for shared/server caches, fall back to max-age.
        $cap = $this->directiveInt($cc, 's-maxage') ?? $this->directiveInt($cc, 'max-age');

        if ($cap !== null) {
            $ttl = min($ttl, max(0, $cap));
        }

        return $ttl;
    }

    /**
     * @param array<string,bool|string> $cc
     */
    private function directiveInt(array $cc, string $key): ?int
    {
        $value = $cc[$key] ?? null;
        if (!\is_string($value) || $value === '' || !\ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function intFromMixed(mixed $value, int $default): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && $value !== '' && \ctype_digit($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function invokeNext(Closure $next, Request $req): Response
    {
        $resp = $next($req);
        if (!$resp instanceof Response) {
            throw new RuntimeException('ResponseCacheMiddleware expects downstream to return Response.');
        }

        return $resp;
    }

    /* ───────────────────────── policy ───────────────────────── */

    private function isCacheable(Response $resp): bool
    {
        // Skip streaming or unknown/oversized bodies.
        if ($resp->isStreaming()) {
            return false;
        }
        $size = $resp->getBody()->getSize();
        if ($size !== null && $size > $this->maxBodyBytes) {
            return false;
        }

        // Do not cache Set-Cookie unless explicitly allowed.
        if ($this->avoidSetCookie && $resp->hasHeader('Set-Cookie')) {
            return false;
        }

        // Status whitelist (RFC 9111-ish + practical micro-cache picks).
        $okStatuses = [
            StatusEnum::OK->value,
            StatusEnum::NON_AUTHORITATIVE_INFO->value,
            StatusEnum::NO_CONTENT->value,
            StatusEnum::MOVED_PERMANENTLY->value,
            StatusEnum::PERMANENT_REDIRECT->value,
            StatusEnum::NOT_FOUND->value,
            StatusEnum::METHOD_NOT_ALLOWED->value,
            StatusEnum::GONE->value,
            StatusEnum::URI_TOO_LONG->value,
            StatusEnum::UNAVAILABLE_FOR_LEGAL_REASONS->value,
        ];
        if (!in_array($resp->getStatusCode(), $okStatuses, true)) {
            return false;
        }

        if (!$this->respectResponseCacheControl) {
            return true;
        }

        // Honor basic Cache-Control signals.
        $cc = $this->parseCacheControl($resp->getHeaderLine('Cache-Control'));
        if (isset($cc['no-store'])) {
            return false;
        }
        // Respect explicit privacy if we treat this store like a shared cache.
        if (isset($cc['private'])) {
            return false;
        }

        return true;
    }

    private function isCacheMethod(string $method): bool
    {
        return $method === HttpMethodEnum::GET->value || $method === HttpMethodEnum::HEAD->value;
    }

    private function isPersonalizedRequest(Request $req): bool
    {
        return $this->skipWhenPersonalized && $req->getAttribute('personalized') === true;
    }

    /* ───────────────────────── keying ───────────────────────── */

    private function makeKey(Request $req): string
    {
        $u = $req->getUri();
        $host = strtolower($u->getHost() ?: 'localhost');
        $path = $u->getPath() ?: '/';
        $query = '';

        if ($this->includeQuery) {
            $qs = $u->getQuery();
            if ($qs !== '') {
                $query = Uri::normalizeQueryString($qs);
            }
        }

        // Prefer accumulator-provided pairs/tokens; fallback to configured default vary surface.
        $pairs = $this->resolveVaryPairs($req);

        // Deterministic order for header surface.
        if ($pairs) {
            ksort($pairs, SORT_STRING);
        }

        $type = $this->stringFromMixed($req->getAttribute('negotiated.type', ''), '');
        $charset = $this->stringFromMixed($req->getAttribute('negotiated.charset', ''), '');
        $locale = $this->stringFromMixed($req->getAttribute('locale', ''), '');

        // Build a compact, delimiter-safe buffer (NUL separators).
        $nul = "\0";
        $buf = HttpMethodEnum::normalize($req->getMethod()) . $nul
            . $host . $nul
            . $path . $nul
            . $query . $nul
            . $type . $nul
            . $charset . $nul
            . $locale;

        foreach ($pairs as $h => $v) {
            $buf .= $nul . $h . $nul . $v;
        }

        return substr(hash('xxh3', $buf, false), 0, 24);
    }

    /**
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $headers = [];
        foreach ($value as $name => $headerValues) {
            if (!\is_string($name) || $name === '') {
                continue;
            }
            if (\is_string($headerValues)) {
                $headers[$name] = [$headerValues];

                continue;
            }
            if (!\is_array($headerValues)) {
                continue;
            }
            $normalizedValues = [];
            foreach ($headerValues as $headerValue) {
                if (\is_string($headerValue)) {
                    $normalizedValues[] = $headerValue;
                }
            }
            $headers[$name] = $normalizedValues;
        }

        return $headers;
    }

    /* ───────────────────────── packing ───────────────────────── */

    /**
     * @return array{
     *   s:int,
     *   h:array<string,list<string>>,
     *   b:string,
     *   pv:string,
     *   rp:string
     * }
     */
    private function pack(Response $r): array
    {
        // Snapshot body safely (assumes non-streaming).
        $body = (string) $r->getBody();

        return [
            's' => $r->getStatusCode(),
            'h' => $this->normalizeHeaders($r->getHeaders()),
            'b' => $body,
            'pv' => $r->getProtocolVersion(),
            'rp' => $r->getReasonPhrase(),
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function parseCacheControl(string $line): array
    {
        if ($line === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $line) as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            if (str_contains($seg, '=')) {
                [$k, $v] = array_map(trim(...), explode('=', $seg, 2));
                $out[strtolower($k)] = trim($v, '"\'');
            } else {
                $out[strtolower($seg)] = true;
            }
        }

        return $out;
    }

    /**
     * Resolve request header/value pairs that participate in cache variance.
     *
     * Sources (in priority order):
     * - Explicit precomputed `vary.pairs` attribute (if supplied by caller).
     * - Pending vary tokens (`__vary_tokens`) registered by VaryAccumulatorMiddleware::add().
     * - Fallback configured default vary headers when neither attribute is present.
     *
     * @return array<string,string>
     */
    private function resolveVaryPairs(Request $req): array
    {
        /** @var array<string,string> $pairs */
        $pairs = (array) $req->getAttribute('vary.pairs');
        if ($pairs !== []) {
            return $pairs;
        }

        // Internal token queue used by VaryAccumulatorMiddleware.
        $pairs = $this->appendVaryTokens($pairs, $req, $req->getAttribute('__vary_tokens'));
        if ($pairs !== []) {
            return $pairs;
        }

        foreach ($this->defaultVary as $name) {
            $line = $req->getHeaderLine($name);
            if ($line !== '') {
                $pairs[$name] = $line;
            }
        }

        return $pairs;
    }

    private function stringFromMixed(mixed $value, string $default): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @param array<mixed,mixed> $data
     */
    private function unpack(array $data): Response
    {
        $headers = $this->normalizeHeaders($data['h'] ?? []);

        return new Response(
            $this->intFromMixed($data['s'] ?? null, StatusEnum::OK->value),
            new Stream($this->stringFromMixed($data['b'] ?? '', '')),
            $headers,
            $this->stringFromMixed($data['pv'] ?? '1.1', '1.1'),
            $this->stringFromMixed($data['rp'] ?? '', ''),
        );
    }
}
