<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router_OLD\Compiler;

use Infocyph\Webrick\Router_OLD\RouteCollection;
use Infocyph\InterMix\Cache\Cache;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Stores and loads the compiled RouteCollection using
 * InterMix Cache::file('webrick') backend.
 */
final class RouteDumper
{
    private const KEY = 'webrick.routes.bin';
    private readonly CacheItemPoolInterface $pool;

    public function __construct(
        private readonly ValueSerializer $codec = new ValueSerializer(),
    ) {
        $this->pool = Cache::file('route', sys_get_temp_dir().DIRECTORY_SEPARATOR.'webrick');
    }

    public function load(): ?RouteCollection
    {
        $item = $this->pool->getItem(self::KEY);
        if (!$item->isHit()) {
            return null;
        }

        /** @var RouteCollection */
        return $this->codec->decode($item->get());
    }

    public function warm(RouteCollection $routes): void
    {
        $item = $this->pool->getItem(self::KEY);
        $item->set($this->codec->encode($routes));
        $this->pool->save($item);
    }

    public function clear(): void
    {
        $this->pool->deleteItem(self::KEY);
    }
}
