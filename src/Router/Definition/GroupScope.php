<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

/**
 * Immutable context for nested route groups:
 *  - URI prefix (normalized to "/foo/bar")
 *  - Optional domain (e.g. "api.example.com")
 *  - Middleware list (class-strings or objects)
 *  - Name prefix (e.g. "api.")
 *
 * All mutators return a new instance; original stays unchanged.
 *
 * @psalm-type MiddlewareList = list<class-string|object>
 */
final class GroupScope
{
    public function __construct(
        private readonly string  $prefix     = '',      // always normalized
        private readonly ?string $domain     = null,
        /** @var MiddlewareList */
        private readonly array   $middleware = [],
        private readonly string  $namePrefix = '',
    ) {}

    /* -----------------------------------------------------------------
     *  Fluent (immutable) setters
     * ----------------------------------------------------------------*/

    public function withPrefix(string $more): self
    {
        $combined = trim($this->prefix, '/') . '/' . trim($more, '/');
        // normalize: ensure leading slash, no trailing slash, collapse '//'
        $normalized = '/' . trim(preg_replace('#/+#', '/', $combined), '/');

        return new self(
            $normalized,
            $this->domain,
            $this->middleware,
            $this->namePrefix
        );
    }

    public function withDomain(?string $domain): self
    {
        return new self(
            $this->prefix,
            $domain,
            $this->middleware,
            $this->namePrefix
        );
    }

    /**
     * @param MiddlewareList $extra
     */
    public function withMiddleware(array $extra): self
    {
        return new self(
            $this->prefix,
            $this->domain,
            [...$this->middleware, ...$extra],  // preserves order
            $this->namePrefix
        );
    }

    public function withNamePrefix(string $namePrefix): self
    {
        return new self(
            $this->prefix,
            $this->domain,
            $this->middleware,
            $this->namePrefix . $namePrefix
        );
    }

    /* -----------------------------------------------------------------
     *  Accessors
     * ----------------------------------------------------------------*/

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /** @return MiddlewareList */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getNamePrefix(): string
    {
        return $this->namePrefix;
    }
}
