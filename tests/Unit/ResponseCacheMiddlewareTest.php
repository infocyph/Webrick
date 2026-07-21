<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

function failingResponseCachePool(bool $failReads): CacheItemPoolInterface
{
    return new class (new ArrayCacheAdapter('webrick-test'), $failReads) implements CacheItemPoolInterface {
        public function __construct(
            private readonly CacheItemPoolInterface $inner,
            private readonly bool $failReads,
        ) {}

        public function clear(): bool
        {
            return $this->inner->clear();
        }

        public function commit(): bool
        {
            return $this->inner->commit();
        }

        public function deleteItem(string $key): bool
        {
            return $this->inner->deleteItem($key);
        }

        public function deleteItems(array $keys): bool
        {
            return $this->inner->deleteItems($keys);
        }

        public function getItem(string $key): CacheItemInterface
        {
            if ($this->failReads) {
                throw new RuntimeException('cache read unavailable');
            }

            return $this->inner->getItem($key);
        }

        public function getItems(array $keys = []): iterable
        {
            return $this->inner->getItems($keys);
        }

        public function hasItem(string $key): bool
        {
            return $this->inner->hasItem($key);
        }

        public function save(CacheItemInterface $item): bool
        {
            throw new RuntimeException('cache write unavailable for ' . $item->getKey());
        }

        public function saveDeferred(CacheItemInterface $item): bool
        {
            throw new RuntimeException('cache write unavailable for ' . $item->getKey());
        }
    };
}

test('response cache fails open when its backend cannot read', function (): void {
    $middleware = new ResponseCacheMiddleware(new Cache(failingResponseCachePool(true)));
    $calls = 0;

    $response = $middleware(
        Request::fake(uri: 'http://localhost/cache-read'),
        static function () use (&$calls): Response {
            $calls++;

            return Response::json(['ok' => true]);
        },
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($calls)->toBe(1);
});

test('response cache preserves the response when its backend cannot write', function (): void {
    $middleware = new ResponseCacheMiddleware(new Cache(failingResponseCachePool(false)));

    $response = $middleware(
        Request::fake(uri: 'http://localhost/cache-write'),
        static fn(): Response => Response::json(['ok' => true]),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('{"ok":true}');
});
