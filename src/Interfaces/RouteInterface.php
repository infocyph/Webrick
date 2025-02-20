<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

/**
 * Represents a single route definition with optional domain and route name
 * for advanced use-cases (subdomain routing, URL generation).
 */
interface RouteInterface
{
    public function setMethod(string $method): self;

    public function getMethod(): string;

    public function setPath(string $path): self;

    public function getPath(): string;

    public function setHandler(callable $handler): self;

    public function getHandler(): callable;

    public function setDomain(?string $domain): self;

    public function getDomain(): ?string;

    public function setName(string $name): self;

    public function getName(): ?string;
}
