<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use Infocyph\Webrick\Interfaces\RouteInterface;

/**
 * Immutable value-object for a single HTTP route.
 *
 *  • All “mutators” return new instances (no internal mutation).
 *  • Typed for PHP 8.4 (readonly promoted properties).
 *  • Absolutely no logic beyond lightweight data-holding.
 */
final class Route implements RouteInterface
{
    public $handler;

    /**
     * @param list<class-string|object> $middlewares
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        callable $handler,
        public readonly ?string $domain = null,
        public readonly ?string $name = null,
        public readonly array $middlewares = [],
    ) {
        $this->handler = $handler;
    }

    /* ------------------------------------------------------------------ */
    /* RouteInterface – core                                              */
    /* ------------------------------------------------------------------ */
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

    /* ------------------------------------------------------------------ */
    /* RouteInterface – meta                                              */
    /* ------------------------------------------------------------------ */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return list<class-string|object> */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /* ------------------------------------------------------------------ */
    /* Immutators (fluent helpers)                                        */
    /* ------------------------------------------------------------------ */
    public function withName(string $name): self
    {
        return $name === $this->name ? $this
            : new self(
                $this->method,
                $this->path,
                $this->handler,
                $this->domain,
                $name,
                $this->middlewares,
            );
    }

    /** @param list<class-string|object> $extra */
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

    public function withDomain(?string $domain): self
    {
        return $domain === $this->domain ? $this
            : new self(
                $this->method,
                $this->path,
                $this->handler,
                $domain,
                $this->name,
                $this->middlewares,
            );
    }
}
