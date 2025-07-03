<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Interfaces\RouteInterface;

/**
 * Immutable value-object representing a single compiled route.
 *
 *  • All “mutators” return **new** instances (no internal mutation).
 *  • Absolutely no logic beyond lightweight data-holding; matching,
 *    constraint parsing and dispatcher-related work live elsewhere.
 *
 * @internal The Router builds these - application code should rely
 *           on the public RouteInterface type-hints only.
 */
final class Route implements RouteInterface
{
    /* -------------------------------------------------------------
       Core (always present)
       ------------------------------------------------------------ */
    private readonly string $method;   // GET / POST / PATCH …
    private readonly string $path;     // canonicalised pattern
    private $handler;  // controller / closure

    /* -------------------------------------------------------------
       Meta (optional, can be added fluently)
       ------------------------------------------------------------ */
    private readonly ?string $domain;
    private readonly ?string $name;
    /** @var array<class-string|object> */
    private readonly array $middlewares;

    /* -------------------------------------------------------------
       Construction
       ------------------------------------------------------------ */
    /**
     * @param array<class-string|object> $middlewares
     */
    public function __construct(
        string $method,
        string $path,
        callable $handler,
        ?string $domain = null,
        ?string $name = null,
        array $middlewares = [],
    ) {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->handler = $handler;
        $this->domain = $domain;
        $this->name = $name;
        $this->middlewares = $middlewares;
    }

    /* -------------------------------------------------------------
       RouteInterface – core
       ------------------------------------------------------------ */

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler(): callable
    {
        return $this->handler;
    }

    public function name(string $name): self
    {
        return $this->withName($name);
    }

    /** @param string|string[] $mw */
    public function middleware(string|array $mw): self
    {
        return $this->withMiddleware((array)$mw);
    }

    /* -------------------------------------------------------------
       RouteInterface – meta
       ------------------------------------------------------------ */

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /* -------------------------------------------------------------
       RouteInterface – immutators
       ------------------------------------------------------------ */

    public function withDomain(?string $domain): self
    {
        if ($domain === $this->domain) {
            return $this;
        }
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $domain,
            $this->name,
            $this->middlewares,
        );
    }

    public function withName(string $name): self
    {
        if ($name === $this->name) {
            return $this;
        }
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $this->domain,
            $name,
            $this->middlewares,
        );
    }

    /**
     * @param array<class-string|object> $extra
     */
    public function withMiddleware(array $extra): self
    {
        if ($extra === []) {
            return $this;
        }
        $merged = array_values(array_unique([...$this->middlewares, ...$extra], SORT_STRING));

        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $this->domain,
            $this->name,
            $merged,
        );
    }
}
