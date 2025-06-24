<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Infocyph\InterMix\Cache\Cache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class TokenBucketThrottleMiddleware implements MiddlewareInterface
{
    use ThrottleKeyHelpers;

    /** @var callable(ServerRequestInterface):string */
    private $keyFn;
    private float $rate; // tokens per second

    public function __construct(
        private int $capacity,
        private int $seconds,
        callable|string $keyResolver = 'ip',
        private Cache $cache = new Cache()
    ) {
        $this->keyFn = is_callable($keyResolver)
            ? $keyResolver
            : self::makeKeyResolver($keyResolver);
        $this->rate = $capacity / $seconds;
    }

    public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
    {
        $id = ($this->keyFn)($r) ?: 'anon';
        $key = 'bucket:' . md5($id);
        $it  = $this->cache->getItem($key);

        $state = $it->get() ?: ['tokens' => $this->capacity, 'ts' => microtime(true)];
        $now   = microtime(true);
        $delta = $now - $state['ts'];
        $tokens = min($this->capacity, $state['tokens'] + $delta * $this->rate);

        if ($tokens < 1) {
            throw new RuntimeException('Too Many Requests', 429);
        }
        $state = ['tokens' => $tokens - 1, 'ts' => $now];
        $it->set($state)->expiresAfter($this->seconds * 2);
        $this->cache->save($it);

        return $h->handle($r)
            ->withHeader('X-RateLimit-Limit', (string) $this->capacity)
            ->withHeader('X-RateLimit-Remaining', (string) floor($state['tokens']));
    }
}
