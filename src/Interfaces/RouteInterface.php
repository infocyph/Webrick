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
    public function getMethod(): string;          // "GET", "POST", …

    public function getPath(): string;            // "/users/{id:int}"

    public function getHandler(): array|string|callable;       // controller / closure

    /* ---------- meta ---------- */
    public function getDomain(): ?string;         // "api.example.com" or null

    public function getName(): ?string;           // "users.show"      or null

    public function getMiddlewares(): array;      // list<class-string|object>

    /* ---------- immutators ---- */
    public function withDomain(?string $domain): self;

    public function withName(string $name): self;

    /** @param array<class-string|object> $extra */
    public function withMiddleware(array $extra): self;
}
