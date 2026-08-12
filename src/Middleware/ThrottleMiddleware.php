<?php

/**
 * Webrick - Throttle middleware (fixed-window).
 *
 * Applies fixed-window request rate limiting and attaches standard headers:
 * - Standard RateLimit-* and legacy X-RateLimit-* headers
 * - Optional Retry-After as seconds or HTTP-date
 * - Per-request cost via request attribute (default: "rate_cost.thm")
 * - Optional bypass callback
 * - Pluggable identifier resolver and scope
 *
 * Place early in the pipeline (after gateway hardening, before app handlers).
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use DateTimeImmutable;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException as InvalidConfigException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Fixed-window throttling with standard headers and flexible configuration.
 */
final readonly class ThrottleMiddleware
{
    private const string CACHE_KEY_PREFIX = 'webrick.th.v1.';

    /**
     * PSR-6 cache pool used to store counters and reset epochs.
     */
    private ?CacheItemPoolInterface $pool;

    /**
     * Configure the throttle middleware.
     *
     * @param int $max Allowed requests per window.
     * @param int $window Window size in seconds.
     * @param CacheItemPoolInterface|null $pool PSR-6 pool (APCu/file fallback by default).
     * @param bool $retryAsDate Format Retry-After as HTTP-date (true) or seconds (false).
     * @param Closure(Request):string|null $identifierResolver Custom key source (default: client IP).
     * @param bool $emitStandardRateLimit Also emit RateLimit-* (besides X-RateLimit-*).
     * @param string $scope Logical bucket (e.g., "global", "auth", "login").
     * @param string $costAttribute Request attribute name used for per-request cost.
     * @param Closure(Request):bool|null $bypass If returns true, request is not throttled.
     * @param AtomicCounterStoreInterface|null $counterStore Optional atomic counter backend; preferred for concurrent workers.
     */
    public function __construct(
        private int $max = 1,
        private int $window = 1,
        ?CacheItemPoolInterface $pool = null,
        private bool $retryAsDate = false,
        private ?Closure $identifierResolver = null,
        private bool $emitStandardRateLimit = true,
        private string $scope = 'global',
        private string $costAttribute = 'rate_cost.thm',
        private ?Closure $bypass = null,
        private ?AtomicCounterStoreInterface $counterStore = null,
    ) {
        if ($this->max < 1) {
            throw new InvalidConfigException('max must be >= 1.');
        }
        if ($this->window < 1) {
            throw new InvalidConfigException('window must be >= 1 second.');
        }
        if ($this->scope === '') {
            throw new InvalidConfigException('scope must be a non-empty string.');
        }
        if ($this->costAttribute === '') {
            throw new InvalidConfigException('costAttribute must be a non-empty string.');
        }

        $this->pool = $pool ?? ($this->counterStore === null ? $this->buildDefaultPool() : null);
    }

    /**
     * Enforce throttling and attach rate-limit headers.
     *
     * Flow:
     * 1) Optional bypass.
     * 2) Anchor timing to request start for consistent math.
     * 3) Compute the bucket key and atomically reserve capacity when configured.
     * 4) If this request exceeds the limit, return 429 with headers.
     * 5) Otherwise call the next handler and attach rate-limit headers.
     *
     * @param Request $req Incoming request.
     * @param Closure(Request):Response $next
     * @return Response Throttled or normal response with rate headers.
     *
     * @throws InvalidArgumentException
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        if ($this->bypass && ($this->bypass)($req) === true) {
            return $next($req);
        }

        // Anchor timing to the start of the request for consistent math
        $now = $this->intFromMixed($_SERVER['REQUEST_TIME'] ?? null, \time());

        [$key, $resetAt] = $this->deriveKeyAndReset($req, $now);
        $cost = max(1, $this->intFromMixed($req->getAttribute($this->costAttribute, 1), 1));
        $hits = $this->reserveCapacity($key, $resetAt, $now, $cost);
        $remain = max(0, $this->max - $hits);
        $resp = $next($req);

        return $this->attachRateHeaders($resp, $remain, $resetAt);
    }

    /**
     * Attach rate-limit headers to a successful response.
     *
     * @param Response $resp Response to augment.
     * @param int $remain Remaining requests in the window.
     * @param int $resetEpoch Window reset epoch.
     * @return Response Response with rate-limit headers.
     */
    private function attachRateHeaders(Response $resp, int $remain, int $resetEpoch): Response
    {
        $delta = $this->secondsUntil($resetEpoch);

        $resp = $resp
            ->withSmartHeader('X-RateLimit-Limit', (string) $this->max)
            ->withSmartHeader('X-RateLimit-Remaining', (string) $remain)
            ->withSmartHeader('X-RateLimit-Reset', (string) $resetEpoch);

        if ($this->emitStandardRateLimit) {
            $resp = $resp
                ->withSmartHeader('RateLimit-Limit', (string) $this->max)
                ->withSmartHeader('RateLimit-Remaining', (string) $remain)
                ->withSmartHeader('RateLimit-Reset', (string) $delta)
                ->withSmartHeader('RateLimit-Policy', "$this->max;w=$this->window");
        }

        return $resp;
    }

    private function base64UrlHash(string $value): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $value, true)), '+/', '-_'), '=');
    }

    private function buildDefaultPool(): CacheItemPoolInterface
    {
        if (!class_exists(Cache::class)) {
            throw new \LogicException(
                'ThrottleMiddleware requires infocyph/cachelayer when no cache pool or atomic counter store is provided.',
            );
        }

        // Windows reports directory permissions differently than POSIX; file-mode checks can false-positive.
        if (\PHP_OS_FAMILY === 'Windows' && !\extension_loaded('apcu')) {
            return Cache::memory('webrick.thm');
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $cacheBase = \is_string($documentRoot) && $documentRoot !== ''
            ? $documentRoot
            : \sys_get_temp_dir() . '/webrick';

        return Cache::file('webrick.throttle', $cacheBase . '.thm');
    }

    private function cachePool(): CacheItemPoolInterface
    {
        return $this->pool ?? throw new \LogicException('No cache pool configured for non-atomic throttling.');
    }

    /**
     * Derive the cache key and reset epoch for the current window.
     *
     * @param Request $req Incoming request.
     * @param int $now Anchor timestamp (usually REQUEST_TIME).
     * @return array{0:string,1:int} Tuple of [cacheKey, resetEpoch].
     */
    private function deriveKeyAndReset(Request $req, int $now): array
    {
        $id = $this->identifierResolver
            ? ($this->identifierResolver)($req)
            : ($req->getAttribute('client_ip')
                ?? $req->getServerParams()['REMOTE_ADDR']
                ?? 'unknown');
        $id = $this->stringFromMixed($id, 'unknown');

        // ---- fixed-window alignment ----
        // Start of the current window (e.g., 12:00:00, 12:01:00, … for w=60)
        $winStart = intdiv($now, $this->window) * $this->window;
        $reset = $winStart + $this->window;

        // Hard-partition by window to avoid cross-window races
        return [
            self::CACHE_KEY_PREFIX . $this->base64UrlHash($this->scope . '|' . $id . '|' . $winStart),
            $reset,
        ];
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

    /**
     * Load the current window payload or initialize a new counter.
     *
     * @param string $key Cache key.
     * @param int $reset Reset epoch for a fresh window.
     * @return array{hits:int, reset:int} Payload.
     *
     * @throws InvalidArgumentException
     */
    private function load(string $key, int $reset): array
    {
        $item = $this->cachePool()->getItem($key);
        $data = $item->isHit() ? $item->get() : null;

        if (!is_array($data)) {
            $data = ['hits' => 0, 'reset' => $reset];
        } else {
            // Normalize
            $hits = $this->intFromMixed($data['hits'] ?? 0, 0);
            $storedReset = $this->intFromMixed($data['reset'] ?? $reset, $reset);
            if ($storedReset <= time()) {
                $data = ['hits' => 0, 'reset' => $reset];
            } else {
                $data = ['hits' => $hits, 'reset' => $storedReset];
            }
        }

        return $data;
    }

    /**
     * Persist the updated payload with an expiry aligned to the reset epoch.
     *
     * @param string $key Cache key.
     * @param array{hits:int,reset:int} $payload Payload to store.
     *
     * @throws InvalidArgumentException
     */
    private function persist(string $key, array $payload): void
    {
        $pool = $this->cachePool();
        $item = $pool->getItem($key);
        $item->set($payload);
        $item->expiresAt(new DateTimeImmutable()->setTimestamp($payload['reset']));
        // Write immediately (less chance of loss/race than saveDeferred)
        $pool->save($item);
    }

    private function reserveCapacity(string $key, int $resetAt, int $now, int $cost): int
    {
        if ($this->counterStore !== null) {
            $hits = $this->counterStore->increment($key, $cost, max(1, $resetAt - $now))->value;
            if ($hits > $this->max) {
                throw $this->tooMany($resetAt);
            }

            return $hits;
        }

        $payload = $this->load($key, $resetAt);
        if ($this->max < $payload['hits'] + $cost) {
            throw $this->tooMany($payload['reset']);
        }

        $payload['hits'] += $cost;
        $this->persist($key, $payload);

        return $payload['hits'];
    }

    /**
     * Compute seconds until the reset epoch based on request anchor time.
     *
     * @param int $resetEpoch Target epoch.
     * @return int Non-negative seconds until reset.
     */
    private function secondsUntil(int $resetEpoch): int
    {
        $t0 = $this->intFromMixed($_SERVER['REQUEST_TIME'] ?? null, time());

        return max(0, $resetEpoch - $t0);
    }

    private function stringFromMixed(mixed $value, string $default): string
    {
        if (\is_string($value) && $value !== '') {
            return $value;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Build a 429 Too Many Requests exception with appropriate headers.
     *
     * @param int $resetEpoch Reset epoch for the current window.
     * @return HttpException 429 exception with Retry-After and rate-limit headers.
     */
    private function tooMany(int $resetEpoch): HttpException
    {
        $delta = $this->secondsUntil($resetEpoch);

        $retry = $this->retryAsDate
            ? gmdate('D, d M Y H:i:s', $resetEpoch) . ' GMT'
            : (string) $delta;

        $headers = [
            'Retry-After' => $retry,
            'X-RateLimit-Limit' => (string) $this->max,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $resetEpoch,
            'Server-Timing' => 'throttle;dur=0',
        ];

        if ($this->emitStandardRateLimit) {
            $headers['RateLimit-Limit'] = (string) $this->max;
            $headers['RateLimit-Remaining'] = '0';
            $headers['RateLimit-Reset'] = (string) $delta;
            $headers['RateLimit-Policy'] = "$this->max;w=$this->window";
        }

        return HttpException::tooManyRequests('Too Many Requests', $headers);
    }
}
