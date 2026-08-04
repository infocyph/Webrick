<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

trait MatcherCacheLifecycleTrait
{
    /**
     * @return array<string, array{0:string,1:?string}>
     */
    public function aliasIndex(): array
    {
        $this->ensureCacheLoaded();

        return $this->alias;
    }

    #[\Override]
    public function canBootFromCache(): bool
    {
        return $this->cacheEnabled && !$this->cacheWriteEnabled && \is_file($this->cacheFile);
    }

    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheFile = $cacheLocation;

        return $this;
    }

    public function enableCacheWrite(bool $enable = true): self
    {
        $this->cacheWriteEnabled = $enable;

        return $this;
    }

    /** @return list<string> */
    public function middlewareRequirements(): array
    {
        $this->ensureCacheLoaded();

        return \array_keys($this->middlewareRequirements);
    }

    private function ensureCacheLoaded(): void
    {
        if ($this->cacheEnabled && !$this->cacheLoaded && \is_file($this->cacheFile)) {
            $this->loadCacheBlob();
        }
    }
}
