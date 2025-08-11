<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use DateTimeImmutable;
use Infocyph\InterMix\Cache\Cache;
use Infocyph\Webrick\Request\Core\Stream;
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
     * @param int $max             allowed requests per window
     * @param int $window          window size in seconds
     * @param CacheItemPoolInterface|null $pool   PSR-6 pool (APCu/file fallback by default)
     * @param bool $retryAsDate    format Retry-After as HTTP-date instead of seconds
     * @param Closure(Request):string|null $identifierResolver  custom key source (default: client IP)
     * @param bool $emitStandardRateLimit  also emit RateLimit-* (in addition to X-RateLimit-*)
     * @param string $scope         logical bucket (e.g., "global", "auth", "login")
     * @param string $costAttribute request attribute name used for per-request cost
     * @param Closure(Request):bool|null $bypass if returns true, request is not throttled
     */
    public function __construct(
        private int $max = 60,
        private int $window = 60,
        ?CacheItemPoolInterface $pool = null,
        private bool $retryAsDate = false,
        private ?Closure $identifierResolver = null,
        private bool $emitStandardRateLimit = true,
        private string $scope = 'global',
        private string $costAttribute = 'rate_cost',
        private ?Closure $bypass = null,
    ) {
        $this->pool = $pool ?? (extension_loaded('apcu')
            ? Cache::apcu('throttle')
            : Cache::file('throttle'));
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        if ($this->bypass && ($this->bypass)($req) === true) {
            return $next($req);
        }

        $now = time();
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
        $key = 't:' . sha1($bucket . '|' . (string)$id);

        // fixed window reset
        $reset = $now + $this->window;

        return [$key, $reset];
    }

    /** @return array{hits:int, reset:int} */
    private function load(string $key, int $reset): array
    {
        $item = $this->pool->getItem($key);
        $data = $item->isHit() ? $item->get() : null;

        if (!is_array($data)) {
            $data = ['hits' => 0, 'reset' => $reset];
        } else {
            // If the record is stale or missing reset, normalize
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
        $item->expiresAt((new DateTimeImmutable())->setTimestamp($payload['reset']));
        $this->pool->saveDeferred($item);
    }

    private function tooMany(int $resetEpoch): Response
    {
        $delta = max(1, $resetEpoch - time());
        $retry = $this->retryAsDate
            ? gmdate('D, d M Y H:i:s', time() + $delta) . ' GMT'
            : (string)$delta;

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Retry-After' => $retry,
            'X-RateLimit-Limit' => (string)$this->max,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string)$resetEpoch,        // epoch (legacy)
        ];

        if ($this->emitStandardRateLimit) {
            $headers += [
                'RateLimit-Limit' => (string)$this->max,
                'RateLimit-Remaining' => '0',
                'RateLimit-Reset' => (string)$delta,           // seconds per spec
            ];
        }

        return new Response(
            status: 429,
            headers: $headers,
            body: new Stream('Too Many Requests'),
        )->withHeader('Server-Timing', 'throttle;dur=0');
    }

    private function attachRateHeaders(Response $resp, int $remain, int $resetEpoch): Response
    {
        $resp = $resp
            ->withHeader('X-RateLimit-Limit', (string)$this->max)
            ->withHeader('X-RateLimit-Remaining', (string)$remain)
            ->withHeader('X-RateLimit-Reset', (string)$resetEpoch); // epoch (legacy)

        if ($this->emitStandardRateLimit) {
            $resp = $resp
                ->withHeader('RateLimit-Limit', (string)$this->max)
                ->withHeader('RateLimit-Remaining', (string)$remain)
                ->withHeader('RateLimit-Reset', (string)max(1, $resetEpoch - time())); // seconds
        }

        return $resp;
    }
}
