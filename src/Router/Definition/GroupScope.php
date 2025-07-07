<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

/**
 * Immutable “context stack” that accumulates route-building hints while
 * descending through nested groups.
 *
 * A scope carries:
 *   • URI prefix         (e.g. "/v1/api")
 *   • host / sub-domain  (e.g. "admin.example.com")
 *   • middleware list    (class-strings | objects)
 *   • name-prefix        (e.g. "api.")
 *
 * Every mutator returns **a new instance** – never mutates in-place.
 */
final class GroupScope
{
    /** @param list<class-string|object> $middleware */
    public function __construct(
        private readonly string $prefix       = '',
        private readonly ?string $domain      = null,
        private readonly array $middleware    = [],
        private readonly string $namePrefix   = '',
    ) {
    }

    /* -----------------------------------------------------------------
     *  Fluent (immutable) setters
     * ----------------------------------------------------------------*/

    public function withPrefix(string $prefix): self
    {
        $p = rtrim($this->prefix, '/') . '/' . ltrim($prefix, '/');
        return new self($p, $this->domain, $this->middleware, $this->namePrefix);
    }

    public function withDomain(?string $domain): self
    {
        return new self($this->prefix, $domain, $this->middleware, $this->namePrefix);
    }

    /** @param list<class-string|object> $extra */
    public function withMiddleware(array $extra): self
    {
        return new self(
            $this->prefix,
            $this->domain,
            array_merge($this->middleware, $extra),
            $this->namePrefix,
        );
    }

    public function withNamePrefix(string $n): self
    {
        return new self($this->prefix, $this->domain, $this->middleware, $this->namePrefix . $n);
    }

    /* -----------------------------------------------------------------
     *  Accessors used by Registrar / Compiler
     * ----------------------------------------------------------------*/

    public function prefix(): string
    {
        return $this->prefix;
    }
    public function domain(): ?string
    {
        return $this->domain;
    }
    /** @return list<class-string|object> */
    public function middleware(): array
    {
        return $this->middleware;
    }
    public function namePrefix(): string
    {
        return $this->namePrefix;
    }
}
