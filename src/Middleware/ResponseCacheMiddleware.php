<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\InterMix\Cache\Cache;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

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
final class ResponseCacheMiddleware
{
    /** Cache store (PSR-16-style wrapper) */
    private Cache $store;

    /**
     * @param Cache|null $store Cache backend; defaults to local('http')
     * @param int $ttlSeconds Base TTL for cache entries (e.g., 10)
     * @param bool $includeQuery Whether to include normalized query in the cache key
     * @param int $maxBodyBytes Upper bound for payload size to cache
     * @param string[] $defaultVary Headers to consider when no vary accumulator present
     * @param bool $skipWhenPersonalized Skip caching if $req->getAttribute('personalized')
     * @param bool $respectResponseCacheControl Honor no-store/private and (s-)maxage caps
     * @param bool $avoidSetCookie Do not cache responses that set cookies
     */
    public function __construct(
        ?Cache $store = null,
        private int $ttlSeconds = 10,
        private bool $includeQuery = true,
        private int $maxBodyBytes = 1_048_576, // 1 MiB
        private array $defaultVary = ['Accept', 'Accept-Language', 'Accept-Encoding'],
        private bool $skipWhenPersonalized = true,
        private bool $respectResponseCacheControl = true,
        private bool $avoidSetCookie = true,
    ) {
        $this->store = $store ?? Cache::local('http');
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        // Cache only safe, idempotent reads.
        $method = HttpMethodEnum::normalize($req->getMethod());
        $isGet = $method === HttpMethodEnum::GET->value;
        $isHead = $method === HttpMethodEnum::HEAD->value;
        if (!$isGet && !$isHead) {
            return $next($req);
        }

        // Do not cache personalized views (e.g., locale from cookie, user-affinity).
        if ($this->skipWhenPersonalized && $req->getAttribute('personalized')) {
            return $next($req);
        }

        // Build key: method + host + path (+query?) + vary surface (+ negotiated attrs).
        $key = $this->makeKey($req);

        // Fast path: serve from cache when available.
        if (($hit = $this->store->get($key)) !== null) {
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
        $resp = $next($req);

        // Decide whether to store.
        if ($this->isCacheable($req, $resp)) {
            $ttl = $this->computeTtl($resp);
            if ($ttl > 0) {
                $this->store->set($key, $this->pack($resp), $ttl);
            }
        }

        return $resp;
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
                static fn (string $part): string => $part === '' ? '' : ucfirst(strtolower($part)),
                explode('-', $token),
            ),
        );
    }

    private function computeTtl(Response $resp): int
    {
        $ttl = max(0, (int)$this->ttlSeconds);
        if (!$this->respectResponseCacheControl) {
            return $ttl;
        }

        $cc = $this->parseCacheControl($resp->getHeaderLine('Cache-Control'));

        // Prefer s-maxage for shared/server caches, fall back to max-age.
        $cap = null;
        if (isset($cc['s-maxage'])) {
            $cap = (int)$cc['s-maxage'];
        } elseif (isset($cc['max-age'])) {
            $cap = (int)$cc['max-age'];
        }

        if ($cap !== null) {
            $ttl = min($ttl, max(0, $cap));
        }

        return $ttl;
    }

    /* ───────────────────────── policy ───────────────────────── */

    private function isCacheable(Request $req, Response $resp): bool
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
                $query = \Infocyph\Webrick\Request\Core\Uri::normalizeQueryString($qs);
            }
        }

        // Prefer accumulator-provided pairs/tokens; fallback to configured default vary surface.
        $pairs = $this->resolveVaryPairs($req);

        // Deterministic order for header surface.
        if ($pairs) {
            ksort($pairs, SORT_STRING);
        }

        $type = (string)$req->getAttribute('negotiated.type', '');
        $charset = (string)$req->getAttribute('negotiated.charset', '');
        $locale = (string)$req->getAttribute('locale', '');

        // Build a compact, delimiter-safe buffer (NUL separators).
        $nul = "\0";
        $buf = HttpMethodEnum::normalize($req->getMethod()) . $nul
            . $host . $nul
            . $path . $nul
            . $query . $nul
            . $type . $nul
            . $charset . $nul
            . $locale;

        if ($pairs) {
            foreach ($pairs as $h => $v) {
                $buf .= $nul . $h . $nul . $v;
            }
        }

        return substr(hash('xxh3', $buf, false), 0, 24);
    }

    /* ───────────────────────── packing ───────────────────────── */

    private function pack(Response $r): array
    {
        // Snapshot body safely (assumes non-streaming).
        $body = (string)$r->getBody();

        return [
            's' => $r->getStatusCode(),
            'h' => $r->getHeaders(),               // array name => values[]
            'b' => $body,
            'pv' => $r->getProtocolVersion(),
            'rp' => $r->getReasonPhrase(),
        ];
    }

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
                [$k, $v] = array_map('trim', explode('=', $seg, 2));
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
        $pairs = (array)$req->getAttribute('vary.pairs');
        if ($pairs !== []) {
            return $pairs;
        }

        // Internal token queue used by VaryAccumulatorMiddleware.
        $tokens = $req->getAttribute('__vary_tokens');
        if (\is_array($tokens) && $tokens !== []) {
            foreach ($tokens as $token) {
                $name = $this->canonicalHeaderToken((string)$token);
                if ($name === '') {
                    continue;
                }
                // Keep empty values too (absence is a valid cache variant).
                $pairs[$name] = $req->getHeaderLine($name);
            }
            if ($pairs !== []) {
                return $pairs;
            }
        }

        foreach ($this->defaultVary as $name) {
            $line = $req->getHeaderLine($name);
            if ($line !== '') {
                $pairs[$name] = $line;
            }
        }

        return $pairs;
    }

    private function unpack(array $data): Response
    {
        // Normalize headers to plain array<string,string|string[]>
        $headers = $data['h'] ?? [];
        return new Response(
            (int)($data['s'] ?? StatusEnum::OK->value),
            new Stream((string)($data['b'] ?? '')),
            $headers,
            (string)($data['pv'] ?? '1.1'),
            (string)($data['rp'] ?? ''),
        );
    }
}
