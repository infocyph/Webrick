<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\InterMix\Cache\Cache;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\Webrick\Request\Request;

/**
 * Per-IP throttling.  Default: **60 requests / 60 s** (1 req / s)
 *
 * ⚠️  Stateless; uses PSR-6 cache (file backend by default).
 *
 * ```php
 * $router->scope()->withMiddleware([
 *     new ThrottleMiddleware( max:100, window:60 ),   // 100/min
 * ]);
 * ```
 */
final readonly class ThrottleMiddleware
{
    private CacheItemPoolInterface $pool;

    public function __construct(
        private int $max = 60,
        private int $window = 60,               // seconds
        ?CacheItemPoolInterface $pool = null,
    ) {
        $this->pool = $pool ?? Cache::file('throttle');
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* 1. key = client IP (already behind TrustProxiesMiddleware) */
        $ip = $req->getAttribute('client_ip')
            ?? $req->getServerParams()['REMOTE_ADDR']
            ?? 'unknown';

        $key = 't:' . sha1($ip);
        $item = $this->pool->getItem($key);

        $isHit = $item->isHit();
        $hits = $isHit ? (int)$item->get() : 0;
        $remaining = max(0, $this->max - $hits - 1);

        if ($hits >= $this->max) {
            $retry = $item->getExpiration()->getTimestamp() - time();
            return new Response(
                status: 429,
                headers: [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Retry-After' => (string)$retry,
                    'X-RateLimit-Limit' => (string)$this->max,
                    'X-RateLimit-Remaining' => '0',
                ],
                body: new Stream('Too Many Requests'),
            );
        }

        /* 2. increment + set expiry if new */
        $item->set($hits + 1);
        if (!$isHit) {
            $item->expiresAfter($this->window);
            $this->pool->save($item);
        } else {
            $this->pool->saveDeferred($item);
        } // defer IO until shutdown

        /* 3. pass to next middleware / handler */
        $resp = $next($req);

        /* 4. echo rate-limit headers */
        return $resp
            ->withHeader('X-RateLimit-Limit', (string)$this->max)
            ->withHeader('X-RateLimit-Remaining', (string)$remaining);
    }
}
