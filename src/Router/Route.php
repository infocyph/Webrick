<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Interfaces\RouteInterface;

/**
 * Immutable route definition.
 */
final class Route implements RouteInterface
{
    /** @var array<int,string|object>  middleware class names | instances */
    private array $middlewares = [];

    public function __construct(
        private string   $method,
        private string   $path,
        private callable $handler,
        private ?string  $domain = null,
        private ?string  $name   = null,
    ) {}

    /* -------- getters -------- */
    public function getMethod(): string        { return $this->method; }
    public function getPath(): string          { return $this->path; }
    public function getHandler(): callable     { return $this->handler; }
    public function getDomain(): ?string       { return $this->domain; }
    public function getName(): ?string         { return $this->name; }
    public function getMiddlewares(): array    { return $this->middlewares; }

    /* -------- immutable modifiers -------- */
    public function withDomain(?string $domain): self
    {
        $c = clone $this; $c->domain = $domain; return $c;
    }
    public function withName(string $name): self
    {
        $c = clone $this; $c->name = $name; return $c;
    }
    public function withMiddleware(array $mw): self
    {
        $c = clone $this;
        $c->middlewares = array_merge($c->middlewares, $mw);
        return $c;
    }

    /* -------- legacy mutator stubs (for BC) -------- */
    public function setMethod(string $m): self { return $this; }
    public function setPath(string $p): self   { return $this; }
    public function setHandler(callable $h): self { return $this; }
    public function setDomain(?string $d): self { return $this->withDomain($d); }
    public function setName(string $n): self   { return $this->withName($n); }
}
