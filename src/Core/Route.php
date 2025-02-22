<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

use Infocyph\Webrick\Interfaces\RouteInterface;

class Route implements RouteInterface
{
    /** @var callable */
    private $handler;
    private ?string $domain = null;
    private ?string $name   = null;

    /** @var string[]|object[] Fully-qualified class names or actual middleware objects */
    private array $middlewares = [];

    public function __construct(private string $method, private string $path, callable $handler)
    {
        $this->handler = $handler;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setHandler(callable $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    public function getHandler(): callable
    {
        return $this->handler;
    }

    public function setDomain(?string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Attach route-specific middleware (PSR-15).
     */
    public function middleware(array $middlewares): self
    {
        $this->middlewares = array_merge($this->middlewares, $middlewares);
        return $this;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}
