<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Core;

use Infocyph\Webrick\Interfaces\RouteInterface;

class Route implements RouteInterface
{
    /** @var callable */
    private $handler;

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
}
