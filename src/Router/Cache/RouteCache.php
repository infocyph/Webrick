<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Infocyph\Webrick\Router\Route\Collection;

final class RouteCache
{
    public function __construct(
        private CacheItemPoolInterface $pool,
        private string $key = 'compiled_routes'
    ) {}

    public function save(Collection $c): void
    {
        $item = $this->pool->getItem($this->key);
        $item->set(serialize($c->all()));                 // quick win; optimise later
        $this->pool->save($item);
    }

    public function load(): ?array
    {
        $item = $this->pool->getItem($this->key);
        return $item->isHit() ? unserialize($item->get()) : null;
    }

    public function clear(): void
    {
        $this->pool->deleteItem($this->key);
    }
}
