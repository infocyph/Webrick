<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Cache\CachePolicy;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\HttpUtils;
use Infocyph\Webrick\Support\VaryContext;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;

/** Small shared response micro-cache using native string response bodies. */
final readonly class ResponseCacheMiddleware
{
    private const string CACHE_KEY_PREFIX = 'webrick.hr.v5.';

    private CachePolicy $policy;

    private CacheItemPoolInterface $store;

    /** @param list<mixed> $defaultVary */
    public function __construct(
        ?CacheItemPoolInterface $store = null,
        private int $ttlSeconds = 10,
        private bool $includeQuery = true,
        private int $maxBodyBytes = 1_048_576,
        private array $defaultVary = ['Accept', 'Accept-Language', 'Accept-Encoding'],
        private bool $skipWhenPersonalized = true,
        private bool $respectResponseCacheControl = true,
        private bool $avoidSetCookie = true,
        ?CachePolicy $policy = null,
    ) {
        if ($this->ttlSeconds < 0 || $this->maxBodyBytes < 0) {
            throw new \InvalidArgumentException('Response cache TTL and maximum body size must be zero or greater.');
        }
        foreach ($this->defaultVary as $header) {
            if (!is_string($header) || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", trim($header)) !== 1) {
                throw new \InvalidArgumentException('Response cache default Vary values must be valid request header names.');
            }
        }

        $this->store = $store ?? $this->buildDefaultStore();
        $this->policy = $policy ?? new CachePolicy();
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        $method = HttpMethodEnum::normalize($req->getMethod());
        if (!$this->policy->lookupAllowed($req, $this->skipWhenPersonalized)) {
            return $this->invokeNext($next, $req);
        }

        $head = $method === HttpMethodEnum::HEAD->value;
        $varyPairs = $this->resolveVaryPairs($req);
        $key = $this->makeKey($req, $head ? HttpMethodEnum::GET->value : $method, $varyPairs);
        $cached = $this->readCache($key);
        if (is_array($cached)) {
            $response = $this->unpack($cached);
            if ($response instanceof Response) {
                return $head ? self::head($response) : $response;
            }
        }

        $response = $this->invokeNext($next, $req);
        if (!$head) {
            $ttl = $this->storeTtl($req, $response, $varyPairs);
            if ($ttl > 0) {
                $this->writeCache($key, $this->pack($response), $ttl);
            }
        }

        return $response;
    }

    /** @return array<string,string> */
    private static function explicitVaryPairs(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $pairs = [];
        foreach ($value as $name => $headerValue) {
            if (is_string($name) && is_string($headerValue)) {
                $pairs[$name] = $headerValue;
            }
        }

        return $pairs;
    }

    private static function head(Response $response): Response
    {
        if (!$response->hasHeader('Content-Length')) {
            $size = $response->getBodySize();
            if ($size !== null) {
                $response = $response->withHeader('Content-Length', (string) $size);
            }
        }

        return $response->withBody('');
    }

    private function buildDefaultStore(): CacheItemPoolInterface
    {
        if (!class_exists(Cache::class)) {
            throw new \LogicException(
                'ResponseCacheMiddleware requires an explicit PSR-6 pool or the optional infocyph/cachelayer package.',
            );
        }

        return PHP_OS_FAMILY === 'Windows' && !extension_loaded('apcu')
            ? Cache::memory('webrick.http')
            : Cache::file('webrick.http');
    }

    private function canonicalHeaderToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        return implode('-', array_map(
            static fn(string $part): string => $part === '' ? '' : ucfirst(strtolower($part)),
            explode('-', $token),
        ));
    }

    /** @param array<string,string> $varyPairs */
    private function hasSafeVary(Response $response, array $varyPairs): bool
    {
        $vary = $response->getHeaderLine('Vary');
        if ($vary === '') {
            return true;
        }

        $keyed = $varyPairs;
        foreach (explode(',', $vary) as $token) {
            $name = $this->canonicalHeaderToken($token);
            if ($name === '' || $name === '*' || $name === 'Authorization' || $name === 'Cookie' || !isset($keyed[$name])) {
                return false;
            }
        }

        return true;
    }

    private function invokeNext(Closure $next, Request $req): Response
    {
        $response = $next($req);
        if (!$response instanceof Response) {
            throw new RuntimeException('ResponseCacheMiddleware expects downstream to return Response.');
        }

        return $response;
    }

    /** @param array<string,string> $pairs */
    private function makeKey(Request $req, string $method, array $pairs): string
    {
        $uri = $req->getUri();
        $scheme = strtolower($uri->getScheme() ?: 'http');
        $host = strtolower($uri->getHost() ?: 'localhost');
        if ($uri->getPort() !== null) {
            $host .= ':' . $uri->getPort();
        }

        $query = $this->includeQuery ? $uri->getQuery() : '';

        ksort($pairs, SORT_STRING);
        $nul = "\0";
        $buffer = $method . $nul
            . $scheme . $nul
            . $host . $nul
            . ($uri->getPath() ?: '/') . $nul
            . $query . $nul
            . $this->stringFromMixed($req->getAttribute('negotiated.type', ''), '') . $nul
            . $this->stringFromMixed($req->getAttribute('negotiated.charset', ''), '') . $nul
            . $this->stringFromMixed($req->getAttribute('locale', ''), '');

        foreach ($pairs as $name => $value) {
            $buffer .= $nul . $name . $nul . $value;
        }

        return self::CACHE_KEY_PREFIX . rtrim(strtr(base64_encode(hash('sha256', $buffer, true)), '+/', '-_'), '=');
    }

    /** @return array{s:int,h:array<string,list<string>>,b:string,pv:string,rp:string,t:int} */
    private function pack(Response $response): array
    {
        return [
            's' => $response->getStatusCode(),
            'h' => Utils::normalizeHeaderValueLists($response->getHeaders()),
            'b' => $response->getStringBody() ?? '',
            'pv' => $response->getProtocolVersion(),
            'rp' => $response->getReasonPhrase(),
            't' => time(),
        ];
    }

    private function readCache(string $key): mixed
    {
        try {
            $item = $this->store->getItem($key);

            return $item->isHit() ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,string> */
    private function resolveVaryPairs(Request $req): array
    {
        $explicit = self::explicitVaryPairs($req->getAttribute('vary.pairs'));
        if ($explicit !== []) {
            return $explicit;
        }

        $context = $req->getAttribute(VaryContext::ATTRIBUTE);
        $tokens = $context instanceof VaryContext ? $context->all() : [];
        if ($tokens === []) {
            $tokens = $this->defaultVary;
        }

        $pairs = [];
        foreach ($tokens as $token) {
            $name = $this->canonicalHeaderToken($token);
            if ($name !== '') {
                $pairs[$name] = $req->getHeaderLine($name);
            }
        }

        return $pairs;
    }

    /** @param array<string,string> $varyPairs */
    private function storeTtl(Request $request, Response $response, array $varyPairs): int
    {
        if ($response->isStreaming() || $response->getStringBody() === null) {
            return 0;
        }
        $size = $response->getBodySize();
        if ($size !== null && $size > $this->maxBodyBytes) {
            return 0;
        }
        if ($this->resolveVaryPairs($request) !== $varyPairs || !$this->hasSafeVary($response, $varyPairs)) {
            return 0;
        }

        return $this->policy->storeTtl(
            $request,
            $response,
            $this->ttlSeconds,
            $this->respectResponseCacheControl,
            $this->avoidSetCookie,
        );
    }

    private function stringFromMixed(mixed $value, string $default): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param array<mixed,mixed> $data */
    private function unpack(array $data): ?Response
    {
        $now = time();
        if (!is_int($data['s'] ?? null)
            || !is_array($data['h'] ?? null)
            || !is_string($data['b'] ?? null)
            || !is_string($data['pv'] ?? null)
            || !is_string($data['rp'] ?? null)
            || !is_int($data['t'] ?? null)
            || $data['t'] < 0 || $data['t'] > $now
            || strlen($data['b']) > $this->maxBodyBytes
        ) {
            return null;
        }

        try {
            $headers = Utils::normalizeHeaderValueLists($data['h']);
            if ($headers !== $data['h']) {
                return null;
            }
            $response = new Response(
                $data['s'],
                $data['b'],
                $headers,
                $data['pv'],
                $data['rp'],
            );
            $age = HttpUtils::parseUnsignedDecimal($response->getHeaderLine('Age')) ?? 0;
            $date = HttpUtils::parseHttpDate($response->getHeaderLine('Date')) ?? $now;
            $resident = max(0, $now - $data['t']);
            $age = min(PHP_INT_MAX - $resident, $age) + $resident;

            return $response->withHeader('Age', (string) max($age, $now - $date));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $payload */
    private function writeCache(string $key, array $payload, int $ttl): void
    {
        try {
            $item = $this->store->getItem($key);
            $item->set($payload);
            $item->expiresAfter($ttl);
            $this->store->save($item);
        } catch (\Throwable) {
            // Response caching is an optimization; backend failure degrades to a miss.
        }
    }
}
