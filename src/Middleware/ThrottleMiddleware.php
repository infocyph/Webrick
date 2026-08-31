<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use DateTimeImmutable;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Middleware\Throttle\AtomicCounterInterface;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException as InvalidConfigException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/** Fixed-window throttling with atomic production counters. */
final readonly class ThrottleMiddleware
{
    private const string CACHE_KEY_PREFIX = 'webrick.th.v2.';

    private ?CacheItemPoolInterface $pool;

    /**
     * @param Closure(Request):string|null $identifierResolver
     * @param Closure(Request):bool|null $bypass
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
        private ?AtomicCounterInterface $counterStore = null,
        bool $allowApproximateFallback = false,
    ) {
        if ($max < 1 || $window < 1) {
            throw new InvalidConfigException('Throttle max/window must be >= 1.');
        }
        if ($scope === '' || $costAttribute === '') {
            throw new InvalidConfigException('Throttle scope and costAttribute must be non-empty.');
        }
        if ($counterStore === null && !$allowApproximateFallback) {
            throw new InvalidConfigException(
                'Production throttling requires AtomicCounterInterface; set allowApproximateFallback=true only for development.',
            );
        }

        $this->pool = $counterStore === null ? ($pool ?? $this->buildDefaultPool()) : null;
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        if ($this->bypass !== null && ($this->bypass)($req) === true) {
            return $next($req);
        }

        $now = $this->requestTime($req);
        [$key, $resetAt] = $this->deriveKeyAndReset($req, $now);
        $cost = max(1, $this->intFromMixed($req->getAttribute($this->costAttribute, 1), 1));
        $hits = $this->reserveCapacity($key, $resetAt, $now, $cost);
        $remaining = max(0, $this->max - $hits);

        return $this->attachRateHeaders($next($req), $remaining, $resetAt, $now);
    }

    private function attachRateHeaders(Response $resp, int $remaining, int $resetEpoch, int $now): Response
    {
        $delta = max(0, $resetEpoch - $now);
        $resp = $resp
            ->withSmartHeader('X-RateLimit-Limit', (string) $this->max)
            ->withSmartHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withSmartHeader('X-RateLimit-Reset', (string) $resetEpoch);

        if (!$this->emitStandardRateLimit) {
            return $resp;
        }

        return $resp
            ->withSmartHeader('RateLimit-Limit', (string) $this->max)
            ->withSmartHeader('RateLimit-Remaining', (string) $remaining)
            ->withSmartHeader('RateLimit-Reset', (string) $delta)
            ->withSmartHeader('RateLimit-Policy', "{$this->max};w={$this->window}");
    }

    private function base64UrlHash(string $value): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $value, true)), '+/', '-_'), '=');
    }

    private function buildDefaultPool(): CacheItemPoolInterface
    {
        if (!class_exists(Cache::class)) {
            throw new \LogicException(
                'Approximate throttle fallback requires infocyph/cachelayer or an explicit PSR-6 pool.',
            );
        }

        return PHP_OS_FAMILY === 'Windows' && !extension_loaded('apcu')
            ? Cache::memory('webrick.thm.approx')
            : Cache::file('webrick.throttle.approx', sys_get_temp_dir() . '/webrick.thm');
    }

    private function cachePool(): CacheItemPoolInterface
    {
        return $this->pool ?? throw new \LogicException('Approximate throttle cache pool is unavailable.');
    }

    /** @return array{0:string,1:int} */
    private function deriveKeyAndReset(Request $req, int $now): array
    {
        $identifier = $this->identifierResolver !== null
            ? ($this->identifierResolver)($req)
            : ($req->getAttribute('client_ip') ?? $req->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $identifier = $this->stringFromMixed($identifier, 'unknown');
        $windowStart = intdiv($now, $this->window) * $this->window;

        return [
            self::CACHE_KEY_PREFIX . $this->base64UrlHash($this->scope . '|' . $identifier . '|' . $windowStart),
            $windowStart + $this->window,
        ];
    }

    private function intFromMixed(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    /** @return array{hits:int,reset:int} @throws InvalidArgumentException */
    private function loadApproximate(string $key, int $reset, int $now): array
    {
        $item = $this->cachePool()->getItem($key);
        $data = $item->isHit() ? $item->get() : null;
        if (!is_array($data)) {
            return ['hits' => 0, 'reset' => $reset];
        }

        $storedReset = $this->intFromMixed($data['reset'] ?? $reset, $reset);
        if ($storedReset <= $now) {
            return ['hits' => 0, 'reset' => $reset];
        }

        return [
            'hits' => max(0, $this->intFromMixed($data['hits'] ?? 0, 0)),
            'reset' => $storedReset,
        ];
    }

    /** @param array{hits:int,reset:int} $payload @throws InvalidArgumentException */
    private function persistApproximate(string $key, array $payload): void
    {
        $pool = $this->cachePool();
        $item = $pool->getItem($key);
        $item->set($payload);
        $item->expiresAt(new DateTimeImmutable()->setTimestamp($payload['reset']));
        if (!$pool->save($item)) {
            throw new \RuntimeException('Unable to persist approximate throttle counter.');
        }
    }

    private function requestTime(Request $request): int
    {
        return $this->intFromMixed($request->getServerParams()['REQUEST_TIME'] ?? null, time());
    }

    /** @throws InvalidArgumentException */
    private function reserveCapacity(string $key, int $resetAt, int $now, int $cost): int
    {
        if ($this->counterStore !== null) {
            $hits = $this->counterStore->increment($key, $cost, max(1, $resetAt - $now));
            if ($hits > $this->max) {
                throw $this->tooMany($resetAt, $now);
            }

            return $hits;
        }

        $payload = $this->loadApproximate($key, $resetAt, $now);
        if ($payload['hits'] + $cost > $this->max) {
            throw $this->tooMany($payload['reset'], $now);
        }
        $payload['hits'] += $cost;
        $this->persistApproximate($key, $payload);

        return $payload['hits'];
    }

    private function stringFromMixed(mixed $value, string $default): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    private function tooMany(int $resetEpoch, int $now): HttpException
    {
        $delta = max(0, $resetEpoch - $now);
        $headers = [
            'Retry-After' => $this->retryAsDate
                ? gmdate('D, d M Y H:i:s', $resetEpoch) . ' GMT'
                : (string) $delta,
            'X-RateLimit-Limit' => (string) $this->max,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $resetEpoch,
            'Server-Timing' => 'throttle;dur=0',
        ];
        if ($this->emitStandardRateLimit) {
            $headers['RateLimit-Limit'] = (string) $this->max;
            $headers['RateLimit-Remaining'] = '0';
            $headers['RateLimit-Reset'] = (string) $delta;
            $headers['RateLimit-Policy'] = "{$this->max};w={$this->window}";
        }

        return HttpException::tooManyRequests('Too Many Requests', $headers);
    }
}
