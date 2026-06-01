<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

interface RouteInterface
{
    /**
     * Retrieve the domain name of this route.
     *
     * This can be a string containing the domain name, or null if the route does not have a domain.
     *
     * @return string|null The domain name of this route, or null if the route does not have a domain.
     */
    public function getDomain(): ?string;

    /**
     * @return array{0:object|class-string,1:string}|string|callable
     */
    public function getHandler(): array|string|callable;

    /**
     * Stable handler fingerprint.
     */
    public function getHandlerId(): string;

    /**
     * Retrieve the HTTP method (GET, POST, PUT, DELETE, etc.)
     * that this route responds to.
     *
     * @return string The HTTP method (e.g. "GET", "POST", etc.)
     */
    public function getMethod(): string;

    /**
     * BC alias for getMiddlewares().
     *
     * @return list<class-string|object>
     */
    public function getMiddleware(): array;

    /**
     * Retrieve the middlewares of this route.
     *
     * This can be an empty array if the route does not have any middlewares,
     * or an array containing the middlewares of the route.
     *
     * @return list<class-string|object> The middlewares of this route.
     */
    public function getMiddlewares(): array;

    /**
     * Retrieve the name of this route.
     *
     * This can be a string containing the name of the route, or null if the route does not have a name.
     *
     * @return string|null The name of this route, or null if the route does not have a name.
     */
    public function getName(): ?string;

    /**
     * Retrieve the path of the route (e.g. "/users", "/users/{id}", etc.).
     *
     * @return string The path of the route.
     */
    public function getPath(): string;

    /**
     * Returns a new instance of the route with the given domain.
     *
     * The domain can be null, in which case the route will not have a domain.
     *
     * @param string|null $domain The domain of the route.
     * @return self A new instance of the route with the given domain.
     */
    public function withDomain(?string $domain): self;

    /**
     * Returns a new instance of the route with the given middlewares.
     *
     * The route will have the middlewares of the original route, plus the given middlewares.
     *
     * @param list<class-string|object> $extra The middlewares to add to the route.
     * @return self A new instance of the route with the given middlewares.
     */
    public function withMiddleware(array $extra): self;

    /**
     * Returns a new instance of the route with the given name.
     *
     * @param string $name The name of the route.
     * @return self A new instance of the route with the given name.
     */
    public function withName(string $name): self;
}
