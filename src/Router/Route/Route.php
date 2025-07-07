<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Infocyph\Webrick\Router\Contracts\RouteInterface;

/**
 * Declarative route definition produced by the Registrar.
 *
 * @psalm-type MiddlewareList = list<class-string|object>
 */
final class Route implements RouteInterface
{
    /** @var MiddlewareList */
    private array $middleware = [];

    /** @var callable */
    private $handler;

    public function __construct(
        private readonly string  $method,
        private readonly string  $path,
        callable                 $handler,
        private ?string          $domain = null,
        private ?string          $name   = null,
    ) {
        $this->handler = $handler;   // property cannot be typed «callable» in PHP
    }

    /* -----------------------------------------------------------------
     *  Functional-style mutators – each returns a NEW instance
     * ----------------------------------------------------------------*/

    public function withDomain(?string $domain): self
    {
        $clone         = clone $this;
        $clone->domain = $domain;
        return $clone;
    }

    /** @param MiddlewareList $middleware */
    public function withMiddleware(array $middleware): self
    {
        if ($middleware === []) {
            return $this;
        }

        $clone              = clone $this;
        $clone->middleware  = [...$clone->middleware, ...$middleware];
        return $clone;
    }

    public function withName(string $name): self
    {
        $clone       = clone $this;
        $clone->name = $name;
        return $clone;
    }

    /* -----------------------------------------------------------------
     *  Accessors required by RouteInterface
     * ----------------------------------------------------------------*/

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
    public function getDomain(): ?string
    {
        return $this->domain;
    }
    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return MiddlewareList */
    public function getMiddlewares(): array
    {
        return $this->middleware;
    }

    /** Compatibility alias for legacy code */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function isDynamic(): bool
    {
        return str_contains($this->path, '{');
    }
}
