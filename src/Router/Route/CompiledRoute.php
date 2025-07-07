<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Infocyph\Webrick\Router\Contracts\RouteInterface;

/**
 * Hot-path DTO consumed by matchers.
 *
 * @psalm-type MiddlewareList = list<class-string|object>
 */
final class CompiledRoute implements RouteInterface
{
    /** @var callable */
    private $handler;

    /**
     * @param MiddlewareList         $middleware
     * @param list<non-empty-string> $variables
     */
    public function __construct(
        private readonly string   $method,
        private readonly string   $path,
        callable                  $handler,
        private readonly ?string  $domain,
        private readonly array    $middleware,
        private readonly ?string  $name,
        private readonly bool     $dynamic,
        private readonly string   $regex,
        private readonly array    $variables,
    ) {
        $this->handler = $handler;
    }

    /* -----------------------------------------------------------------
     *  Interface – accessors
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

    /** Legacy alias kept for BC */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /* -----------------------------------------------------------------
     *  Matcher helpers
     * ----------------------------------------------------------------*/

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }
    public function getRegex(): string
    {
        return $this->regex;
    }

    /** @return list<non-empty-string> */
    public function getVariables(): array
    {
        return $this->variables;
    }

    public function getPathLength(): int
    {
        return $this->path === '/'
            ? 0
            : substr_count(trim($this->path, '/'), '/') + 1;
    }

    /* -----------------------------------------------------------------
     *  Functional mutators – return **NEW** instance
     * ----------------------------------------------------------------*/

    public function withDomain(?string $domain): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $domain,
            $this->middleware,
            $this->name,
            $this->dynamic,
            $this->regex,
            $this->variables
        );
    }

    /** @param MiddlewareList $middleware */
    public function withMiddleware(array $middleware): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $this->domain,
            [...$this->middleware, ...$middleware],
            $this->name,
            $this->dynamic,
            $this->regex,
            $this->variables
        );
    }

    public function withName(string $name): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $this->domain,
            $this->middleware,
            $name,
            $this->dynamic,
            $this->regex,
            $this->variables
        );
    }
}
