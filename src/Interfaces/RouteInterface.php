<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

/**
 * Read-only DTO representing a single compiled route.
 * Every mutator MUST return a **new** instance.
 *
 * Placeholders are validated via named constraints (see Router docs).
 */
interface RouteInterface
{
    /* ---------- core ---------- */
    public function method(): string;     // "GET", "POST", …
    public function path(): string;     // "/users/{id:int}"
    public function handler(): callable;   // invokable controller

    /* ---------- meta ---------- */
    public function domain(): ?string;    // "api.example.com" or null
    public function name(): ?string;    // "users.show"      or null
    public function middleware(): array;     // list<class-string|object>

    /* ---------- immutators ---- */
    public function withDomain(?string $domain): self;
    public function withName(string $name): self;
    /** @param array<class-string|object> $extra */
    public function withMiddleware(array $extra): self;
}
