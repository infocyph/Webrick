<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

/**
 * Represents a single route definition:
 * - HTTP method
 * - URI path (which can include placeholders)
 * - Handler (callable/controller reference)
 */
interface RouteInterface
{
    public function setMethod(string $method): self;
    public function getMethod(): string;

    public function setPath(string $path): self;
    public function getPath(): string;

    public function setHandler(callable $handler): self;
    public function getHandler(): callable;
}
