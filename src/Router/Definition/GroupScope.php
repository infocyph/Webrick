<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

/**
 * GroupScope
 *
 * Immutable context object used when registering or composing nested route groups.
 *
 * Encapsulates a normalized URI prefix, an optional host/domain constraint,
 * an ordered middleware list and a route name prefix. Instances are immutable;
 * mutator-style methods return new instances leaving the original unchanged.
 *
 * Consumers (the router/registrar) use GroupScope when composing route registration
 * state for nested groups so that prefix, domain, middleware and name prefix are
 * applied consistently.
 *
 *
 * @psalm-type MiddlewareList = list<class-string|object>
 */
final readonly class GroupScope
{
    /**
     * Prefix applied to routes within this scope.
     *
     * Always normalized to have a leading slash, no trailing slash and no
     * duplicate path separators (e.g. "/api/v1").
     *
     * This value is provided via constructor promotion and is read-only.
     *
     * @var string
     */
    public function __construct(
        /**
         * Normalized path prefix (leading slash, no trailing slash).
         *
         * Example: "/api", "/admin/users"
         *
         * @var string
         */
        private string $prefix = '',

        /**
         * Optional domain constraint for the scope (e.g. "api.example.com").
         *
         * When null the scope does not impose a host restriction.
         *
         * @var string|null
         */
        private ?string $domain = null,

        /**
         * Middleware list applied to routes in this scope.
         *
         * Elements are either middleware class-strings or instantiated middleware
         * objects. Order is preserved when composing middleware stacks.
         *
         * @var MiddlewareList
         */
        private array $middleware = [],

        /**
         * Name prefix that is prepended to route names registered in this scope.
         *
         * Example: "admin." so a route named "users" becomes "admin.users".
         *
         * @var string
         */
        private string $namePrefix = '',
    ) {}

    /**
     * Get the optional domain constraint for this scope.
     *
     * @return string|null Domain name or null if none set
     */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * Get the middleware list for this scope.
     *
     * Returns the middleware entries in declared order. Each element is either
     * a class-string or an instantiated middleware object.
     *
     * @return MiddlewareList
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get the route name prefix for this scope.
     *
     * @return string Name prefix (may be empty)
     */
    public function getNamePrefix(): string
    {
        return $this->namePrefix;
    }

    /* -----------------------------------------------------------------
     *  Accessors
     * ----------------------------------------------------------------*/

    /**
     * Get the normalized URI prefix for this scope.
     *
     * @return string Normalized prefix (leading slash, no trailing slash)
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Return a new GroupScope with a specific domain constraint.
     *
     * Passing null clears any domain constraint.
     *
     * @param string|null $domain Domain name to constrain the routes to, or null
     * @return self New instance with updated domain
     */
    public function withDomain(?string $domain): self
    {
        return new self(
            $this->prefix,
            $domain,
            $this->middleware,
            $this->namePrefix,
        );
    }

    /**
     * Return a new GroupScope with additional middleware appended.
     *
     * The $extra list is concatenated to the existing middleware preserving
     * declaration order. Use this to compose middleware stacks for nested groups.
     *
     * @param MiddlewareList $extra List of middleware (class-string or object)
     * @return self New instance with merged middleware list
     */
    public function withMiddleware(array $extra): self
    {
        return new self(
            $this->prefix,
            $this->domain,
            [...$this->middleware, ...$extra],  // preserves order
            $this->namePrefix,
        );
    }

    /**
     * Return a new GroupScope with an extended name prefix.
     *
     * The provided $namePrefix is appended to the existing name prefix.
     *
     * @param string $namePrefix Suffix to append to the current name prefix
     * @return self New instance with updated name prefix
     */
    public function withNamePrefix(string $namePrefix): self
    {
        return new self(
            $this->prefix,
            $this->domain,
            $this->middleware,
            $this->namePrefix . $namePrefix,
        );
    }

    /* -----------------------------------------------------------------
     *  Fluent (immutable) setters
     * ----------------------------------------------------------------*/

    /**
     * Return a new GroupScope with an extended path prefix.
     *
     * The provided $more segment is concatenated to the existing prefix and
     * the result is normalized (leading slash, no trailing slash, collapsed
     * duplicate separators).
     *
     * @param string $more Additional path segment to append (e.g. "/v2" or "v2")
     * @return self New instance with updated prefix
     */
    public function withPrefix(string $more): self
    {
        $combined = trim($this->prefix, '/') . '/' . trim($more, '/');
        // Normalize: ensure leading slash, remove trailing slash and collapse duplicate '/'
        $normalized = '/' . trim((string) preg_replace('#/+#', '/', $combined), '/');

        return new self(
            $normalized,
            $this->domain,
            $this->middleware,
            $this->namePrefix,
        );
    }
}
