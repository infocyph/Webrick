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

/**
 * $middleware = new ThrottleMiddleware(
 *      max: 100,
 *      window: 60,
 *      identifierResolver: function (Request $request) {
 *          // Return the user ID if it exists, otherwise fall back to the IP
 *          return $request->getAttribute('user_id') ?? $request->ip();
 *          // or we could use etc.
 *          return $request->header('X-Api-Key');
 *      }
 * );
 */
final readonly class ThrottleMiddleware
{
    private CacheItemPoolInterface $pool;

    public function __construct(
        private int  $max         = 60,
        private int  $window      = 60,      // seconds
        ?CacheItemPoolInterface $pool = null,
        private bool $retryAsDate = false,   // “Wed, 17 Jul … GMT” vs “120”
        private ?Closure $identifierResolver = null,
    ) {
        $this->pool = $pool ?? (extension_loaded('apcu')
            ? Cache::apcu('throttle')
            : Cache::file('throttle'));
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* 1. derive cache-key ------------------------------------------------*/
        if ($this->identifierResolver) {
            $identifier = ($this->identifierResolver)($req);
        } else {
            $identifier = $req->getAttribute('client_ip')
                ?? $req->getServerParams()['REMOTE_ADDR']
                ?? 'unknown';
        }

        $key  = 't:' . sha1($identifier);
        $item = $this->pool->getItem($key);

        /* 2. unpack payload --------------------------------------------------*/
        $payload = $item->isHit() ? $item->get() : null;

        // Legacy integer? upgrade in-place
        if (\is_int($payload)) {
            $payload = ['hits' => $payload, 'reset' => time() + $this->window];
        }

        if (!\is_array($payload)) {          // first visit
            $payload = ['hits' => 0, 'reset' => time() + $this->window];
        }

        $hits   = $payload['hits'];
        $reset  = $payload['reset'];
        $remain = max(0, $this->max - $hits - 1);

        /* 3. limit exceeded? -------------------------------------------------*/
        if ($hits >= $this->max) {
            $retryAfter = $this->formatRetryAfter($reset - time());

            return new Response(
                status  : 429,
                headers : [
                    'Content-Type'       => 'text/plain; charset=utf-8',
                    'Retry-After'        => $retryAfter,
                    'X-RateLimit-Limit'  => (string) $this->max,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset'  => (string) $reset,
                ],
                body    : new Stream('Too Many Requests')
            );
        }

        /* 4. increment & persist -------------------------------------------*/
        $payload['hits'] = $hits + 1;
        $item->set($payload);
        // Align cache expiration with our reset-timestamp
        $item->expiresAt(new DateTimeImmutable()->setTimestamp($reset));
        $this->pool->saveDeferred($item);

        /* 5. downstream -----------------------------------------------------*/
        $resp = $next($req);

        return $resp
            ->withHeader('X-RateLimit-Limit', (string) $this->max)
            ->withHeader('X-RateLimit-Remaining', (string) $remain)
            ->withHeader('X-RateLimit-Reset', (string) $reset);
    }

    /* -----------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------- */
    private function formatRetryAfter(int $seconds): string
    {
        $seconds = max(1, $seconds); // never zero/negative
        return $this->retryAsDate
            ? gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT'
            : (string) $seconds;
    }
}
