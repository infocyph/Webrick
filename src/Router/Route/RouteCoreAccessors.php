<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

trait RouteCoreAccessors
{
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getHandler(): array|string|callable
    {
        return $this->handler;
    }

    public function getHandlerId(): string
    {
        return $this->handlerId;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getMiddlewares(): array
    {
        return $this->middleware;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
