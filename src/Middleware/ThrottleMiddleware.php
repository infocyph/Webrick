<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use DateTimeImmutable;
use Infocyph\InterMix\Cache\Cache;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Fixed-window throttling with standard headers.
 *
 * Features:
 *  • Standard RateLimit-* + legacy X-RateLimit-* headers
 *  • Optional Retry-After as seconds or HTTP-date
 *  • Cost-per-request via request attribute (default: "rate_cost")
 *  • Optional bypass callback
 *  • Pluggable identifier resolver and scope
 *
 * Place early in the stack (after GatewayHardening, before app handlers).
 */
final readonly class ThrottleMiddleware
{
    private CacheItemPoolInterface $pool;

    /**
     * @param int $max allowed requests per window
     * @param int $window window size in seconds
     * @param CacheItemPoolInterface|null $pool PSR-6 pool (APCu/file fallback by default)
     * @param bool $retryAsDate format Retry-After as HTTP-date instead of seconds
     * @param Closure(Request):string|null $identifierResolver custom key source (default: client IP)
     * @param bool $emitStandardRateLimit also emit RateLimit-* (in addition to X-RateLimit-*)
     * @param string $scope logical bucket (e.g., "global", "auth", "login")
     * @param string $costAttribute request attribute name used for per-request cost
     * @param Closure(Request):bool|null $bypass if returns true, request is not throttled
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
    ) {
        $this->pool = $pool ?? Cache::local($_SERVER['DOCUMENT_ROOT'] . '.thm');
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        if ($this->bypass && ($this->bypass)($req) === true) {
            return $next($req);
        }

        // Anchor timing to the start of the request for consistent math
        $now = (int)($_SERVER['REQUEST_TIME'] ?? time());

        [$key, $resetAt] = $this->deriveKeyAndReset($req, $now);
        $payload = $this->load($key, $resetAt);

        $cost = max(1, (int)$req->getAttribute($this->costAttribute, 1));

        // Will this request exceed the limit?
        if ($payload['hits'] + $cost > $this->max) {
            return $this->tooMany($payload['reset']);
        }

        // Count this request *before* executing the handler
        $payload['hits'] += $cost;
        $this->persist($key, $payload);

        $remain = max(0, $this->max - $payload['hits']);
        $resp = $next($req);

        return $this->attachRateHeaders($resp, $remain, $payload['reset']);
    }

    /** @return array{0:string,1:int} */
    private function deriveKeyAndReset(Request $req, int $now): array
    {
        $id = $this->identifierResolver
            ? ($this->identifierResolver)($req)
            : ($req->getAttribute('client_ip')
                ?? $req->getServerParams()['REMOTE_ADDR']
                ?? 'unknown');

        $bucket = $this->scope;

        // ---- fixed-window alignment ----
        // Start of the current window (e.g., 12:00:00, 12:01:00, … for w=60)
        $winStart = intdiv($now, $this->window) * $this->window;
        $reset = $winStart + $this->window;

        // Hard-partition by window to avoid cross-window races
        return [
            't.' . hash('xxh3', $bucket . '|' . (string)$id . '|' . $winStart, false),
            $reset
        ];
    }

    /** @return array{hits:int, reset:int} */
    private function load(string $key, int $reset): array
    {
        $item = $this->pool->getItem($key);
        $data = $item->isHit() ? $item->get() : null;

        if (!is_array($data)) {
            $data = ['hits' => 0, 'reset' => $reset];
        } else {
            // Normalize
            $data['hits'] = (int)($data['hits'] ?? 0);
            $data['reset'] = (int)($data['reset'] ?? $reset);
            if ($data['reset'] <= time()) {
                $data = ['hits' => 0, 'reset' => $reset];
            }
        }
        return $data;
    }

    private function persist(string $key, array $payload): void
    {
        $item = $this->pool->getItem($key);
        $item->set($payload);
        $item->expiresAt(new DateTimeImmutable()->setTimestamp($payload['reset']));
        // Write immediately (less chance of loss/race than saveDeferred)
        $this->pool->save($item);
    }

    private function secondsUntil(int $resetEpoch): int
    {
        $t0 = (int)($_SERVER['REQUEST_TIME'] ?? time());
        return max(0, $resetEpoch - $t0);
    }

    private function tooMany(int $resetEpoch): Response
    {
        $delta = $this->secondsUntil($resetEpoch);

        $retry = $this->retryAsDate
            ? gmdate('D, d M Y H:i:s', $resetEpoch) . ' GMT'
            : (string)$delta;

        $resp = Response::plaintext('Too Many Requests', 429)
            ->withSmartHeader('Retry-After', $retry)
            ->withSmartHeader('X-RateLimit-Limit', (string)$this->max)
            ->withSmartHeader('X-RateLimit-Remaining', '0')
            ->withSmartHeader('X-RateLimit-Reset', (string)$resetEpoch);

        if ($this->emitStandardRateLimit) {
            $resp = $resp
                ->withSmartHeader('RateLimit-Limit', (string)$this->max)
                ->withSmartHeader('RateLimit-Remaining', '0')
                ->withSmartHeader('RateLimit-Reset', (string)$delta)
                ->withSmartHeader('RateLimit-Policy', "{$this->max};w={$this->window}");
        }

        return $resp->withSmartHeader('Server-Timing', 'throttle;dur=0');
    }

    private function attachRateHeaders(Response $resp, int $remain, int $resetEpoch): Response
    {
        $delta = $this->secondsUntil($resetEpoch);

        $resp = $resp
            ->withSmartHeader('X-RateLimit-Limit', (string)$this->max)
            ->withSmartHeader('X-RateLimit-Remaining', (string)$remain)
            ->withSmartHeader('X-RateLimit-Reset', (string)$resetEpoch);

        if ($this->emitStandardRateLimit) {
            $resp = $resp
                ->withSmartHeader('RateLimit-Limit', (string)$this->max)
                ->withSmartHeader('RateLimit-Remaining', (string)$remain)
                ->withSmartHeader('RateLimit-Reset', (string)$delta)
                ->withSmartHeader('RateLimit-Policy', "{$this->max};w={$this->window}");
        }

        return $resp;
    }
}
