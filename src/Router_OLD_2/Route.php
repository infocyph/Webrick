<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router_OLD;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Immutable description of a single HTTP route.
 *
 * Every “mutator” returns a cloned instance.
 */
final class Route implements RouteInterface
{
    private $handler;

    /** @param array<string|MiddlewareInterface> $middlewares */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        callable $handler,
        private readonly ?string $domain = null,
        private readonly ?string $name = null,
        private readonly array $middlewares = [],
    ) {
        $this->handler = $handler;
    }

    /* -------------------------------- getters ------------------------------- */

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

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /* ------------------------------ immutators ------------------------------ */

    public function withName(string $name): self
    {
        return new self($this->method, $this->path, $this->handler, $this->domain, $name, $this->middlewares);
    }

    public function withDomain(string|null $domain): self
    {
        return new self($this->method, $this->path, $this->handler, $domain, $this->name, $this->middlewares);
    }

    /** @param array<string|MiddlewareInterface> $middlewares */
    public function withMiddleware(array $middlewares): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $this->domain,
            $this->name,
            array_merge($this->middlewares, $middlewares),
        );
    }
}
