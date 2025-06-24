<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

/**
 * Immutable representation of a single route.
 * Every mutator returns a *new* instance.
 */
interface RouteInterface
{
    /* ---------- core accessors ---------- */
    public function getMethod(): string;            // "GET", "POST", …
    public function getPath(): string;              // "/users/{id:int}"
    public function getHandler(): callable;         // controller callable

    /* ---------- optional extras ---------- */
    public function getDomain(): ?string;           // "api.example.com" or null
    public function getName(): ?string;             // "users.show" or null

    /** @return array<int,string|object>  PSR-15 middleware classes|objects */
    public function getMiddlewares(): array;

    /* ---------- immutable modifiers ----- */
    public function withDomain(?string $domain): self;
    public function withName(string $name): self;

    /**
     * Returns a copy with additional route-specific middleware appended.
     *
     * @param array<int,string|object> $middlewares
     */
    public function withMiddleware(array $middlewares): self;
}
