<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Middleware;

use Infocyph\InterMix\Cache\Cache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class ThrottleMiddleware implements MiddlewareInterface
{
    use ThrottleKeyHelpers;

    /** @var callable(ServerRequestInterface):string */
    private $keyFn;

    public function __construct(
        private int $limit,
        private int $seconds,
        callable|string $keyResolver = 'ip',
        private Cache $cache = new Cache()
    ) {
        $this->keyFn = is_callable($keyResolver)
            ? $keyResolver
            : self::makeKeyResolver($keyResolver);
    }

    public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
    {
        $id  = ($this->keyFn)($r) ?: 'anon';
        $key = 'throttle:' . md5($id);
        $it  = $this->cache->getItem($key);

        $hits = (int) ($it->get() ?? 0) + 1;
        if ($hits === 1) {
            $it->expiresAfter($this->seconds);
        }
        $it->set($hits);
        $this->cache->save($it);

        if ($hits > $this->limit) {
            throw new RuntimeException('Too Many Requests', 429);
        }
        return $h->handle($r)
            ->withHeader('X-RateLimit-Limit', (string) $this->limit)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $this->limit - $hits));
    }
}
