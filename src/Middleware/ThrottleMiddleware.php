<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use DateTimeImmutable;
use Infocyph\InterMix\Cache\Cache;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;
use Psr\Cache\CacheItemPoolInterface;

final readonly class ThrottleMiddleware
{
    private CacheItemPoolInterface $pool;

    public function __construct(
        private int  $max         = 60,
        private int  $window      = 60,      // seconds
        ?CacheItemPoolInterface $pool = null,
        private bool $retryAsDate = false,   // “Wed, 17 Jul … GMT” vs “120”
        private ?Closure $identifierResolver = null,
        private bool $emitStandardRateLimit = true, // ← NEW
    ) {
        $this->pool = $pool ?? (extension_loaded('apcu')
            ? Cache::apcu('throttle')
            : Cache::file('throttle'));
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        [$key, $reset] = $this->deriveKeyAndReset($req);
        $payload       = $this->loadPayload($key, $reset);

        if ($this->isExceeded($payload)) {
            return $this->limitExceededResponse($payload);
        }

        // remaining AFTER counting this request
        $remain = max(0, $this->max - $payload['hits'] - 1);

        $this->persist($key, $payload);

        $resp = $next($req);

        return $this->attachRateHeaders($resp, $remain, $payload['reset']);
    }

    /** @return array{0:string,1:int} */
    private function deriveKeyAndReset(Request $req): array
    {
        $identifier = $this->identifierResolver
            ? ($this->identifierResolver)($req)
            : ($req->getAttribute('client_ip')
                ?? $req->getServerParams()['REMOTE_ADDR']
                ?? 'unknown');

        $key   = 't:' . sha1((string) $identifier);
        $reset = time() + $this->window;

        return [$key, $reset];
    }

    /** @return array{hits:int, reset:int} */
    private function loadPayload(string $key, int $reset): array
    {
        $item    = $this->pool->getItem($key);
        $payload = $item->isHit() ? $item->get() : null;

        if (\is_int($payload)) {
            $payload = ['hits' => $payload, 'reset' => $reset];
        }
        if (!\is_array($payload)) {
            $payload = ['hits' => 0, 'reset' => $reset];
        }
        $payload['hits']  = (int) $payload['hits'];
        $payload['reset'] = (int) $payload['reset'];

        return $payload;
    }

    private function isExceeded(array $payload): bool
    {
        return $payload['hits'] >= $this->max;
    }

    private function limitExceededResponse(array $payload): Response
    {
        $resetDelta = max(1, $payload['reset'] - time());             // ← delta for spec
        $retryAfter = $this->formatRetryAfter($resetDelta);

        $headers = [
            'Content-Type'          => 'text/plain; charset=utf-8',
            'Retry-After'           => $retryAfter,
            'X-RateLimit-Limit'     => (string) $this->max,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset'     => (string) $payload['reset'],     // epoch (BC)
        ];

        if ($this->emitStandardRateLimit) {
            $headers += [
                'RateLimit-Limit'     => (string) $this->max,
                'RateLimit-Remaining' => '0',
                'RateLimit-Reset'     => (string) $resetDelta,         // spec: seconds
            ];
        }

        return new Response(
            status  : 429,
            headers : $headers,
            body    : new Stream('Too Many Requests')
        )->withHeader('Server-Timing', 'throttle;dur=0');
    }

    private function persist(string $key, array $payload): void
    {
        $payload['hits']++;

        $item = $this->pool->getItem($key);
        $item->set($payload);
        $item->expiresAt(new DateTimeImmutable()->setTimestamp($payload['reset']));
        $this->pool->saveDeferred($item);
    }

    private function attachRateHeaders(Response $resp, int $remain, int $reset): Response
    {
        $resp = $resp
            ->withHeader('X-RateLimit-Limit', (string) $this->max)
            ->withHeader('X-RateLimit-Remaining', (string) $remain)
            ->withHeader('X-RateLimit-Reset', (string) $reset);        // epoch (BC)

        if ($this->emitStandardRateLimit) {
            $resp = $resp
                ->withHeader('RateLimit-Limit', (string) $this->max)
                ->withHeader('RateLimit-Remaining', (string) $remain)
                ->withHeader('RateLimit-Reset', (string) max(1, $reset - time())); // seconds
        }

        return $resp;
    }

    private function formatRetryAfter(int $seconds): string
    {
        $seconds = max(1, $seconds); // never zero/negative
        return $this->retryAsDate
            ? gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT'
            : (string) $seconds;
    }
}
