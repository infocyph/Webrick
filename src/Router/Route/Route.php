<?php

/**
 * Webrick - Declarative route definition.
 *
 * Represents a single HTTP route as produced by the Registrar, including its HTTP
 * method, path template, handler specification, optional domain/name, CORS policy,
 * and middleware stack. Provides immutable "with*" methods for safe modifications.
 *
 * @package Infocyph\Webrick\Router\Route
 */

/* src/Router/Route/Route.php */

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

/**
 * Declarative route definition produced by the Registrar.
 *
 * @psalm-type MiddlewareList = list<class-string|object>
 */
final class Route implements RouteInterface
{
    /**
     * One-time, stable fingerprint for the handler, used for fast lookups.
     *
     * @var string
     */
    private readonly string $handlerId;

    /** @var Cors|null The route-specific CORS policy. */
    private ?Cors $corsPolicy = null;

    /**
     * Handler specification accepted by the router.
     *
     * Examples:
     * - "functionName"
     * - ["ClassName", "method"]
     * - [$object, "method"]
     * - invokable object instance
     * - Closure
     *
     * @var array|string|callable
     */
    private $handler;
    /**
     * Middleware to apply for this route.
     *
     * @var MiddlewareList
     */
    private array $middleware = [];

    /**
     * Create a new route.
     *
     * @param string $method HTTP method (e.g., GET, POST).
     * @param string $path Path template (may contain parameters like "{id}").
     * @param array|string|callable $handler Route handler specification.
     * @param string|null $domain Optional host/domain constraint.
     * @param string|null $name Optional route name (for URL generation).
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        array|string|callable $handler,
        private ?string $domain = null,
        private ?string $name = null,
    ) {
        $this->handler = $handler;
        $this->handlerId = self::fingerprint($handler);
    }

    /* ---------------------------------------------------------- internals */

    /**
     * Produce a stable, stringable id for any handler specification.
     *
     * - "function" → "function"
     * - ["Cls","method"] → "Cls::method"
     * - [$object,"method"] → "<Class>::method"
     * - Invokable object → "<Class>::__invoke"
     * - Closure → "closure@<uniqueId>"
     *
     * @param array|string|callable $h Handler specification.
     *
     * @return string Stable identifier for hashing and lookups.
     */
    public static function fingerprint(array|string|callable $h): string
    {
        return match (true) {
            \is_string($h) => $h,                //  "function"  or  "Cls::method"
            \is_array($h) => (is_object($h[0]) ? $h[0]::class : $h[0]) . '::' . $h[1],
            \is_object($h) && !($h instanceof \Closure) => $h::class . '::__invoke',
            default => 'closure@' . \spl_object_id($h),
        };
    }

    /**
     * Get the route-specific CORS policy.
     *
     * @return Cors|null
     */
    public function getCorsPolicy(): ?Cors
    {
        return $this->corsPolicy;
    }

    /**
     * Get the domain constraint, if any.
     *
     * @return string|null
     */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * Get the handler specification.
     *
     * @return array|string|callable
     */
    public function getHandler(): array|string|callable
    {
        return $this->handler;
    }

    /**
     * Fast, stable identifier for the handler (used by collections).
     *
     * @return string
     */
    public function getHandlerId(): string
    {
        return $this->handlerId;
    }

    /* ---------------------------------------------------------- getters */

    /**
     * Get the HTTP method.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Alias of getMiddlewares().
     *
     * @return list<class-string|object>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get the middleware stack.
     *
     * @return list<class-string|object>
     */
    public function getMiddlewares(): array
    {
        return $this->middleware;
    }

    /**
     * Get the route name, if any.
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get the route path template.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Whether the path contains dynamic parameters (e.g., "{id}").
     *
     * @return bool
     */
    public function isDynamic(): bool
    {
        return str_contains($this->path, '{');
    }

    /**
     * Return a copy with a route-specific CORS policy.
     *
     * @param Cors $policy
     *
     * @return self
     */
    public function withCorsPolicy(Cors $policy): self
    {
        $clone = clone $this;
        $clone->corsPolicy = $policy;
        return $clone;
    }

    /* ---------------------------------------------------------- immutable setters (carry id unchanged) */

    /**
     * Return a copy with the given domain constraint.
     *
     * @param string|null $domain
     *
     * @return self
     */
    public function withDomain(?string $domain): self
    {
        $clone = clone $this;
        $clone->domain = $domain;
        return $clone;
    }

    /**
     * Return a copy with additional middleware appended.
     *
     * No-op if the provided list is empty.
     *
     * @param list<class-string|object> $middleware
     *
     * @return self
     */
    public function withMiddleware(array $middleware): self
    {
        if ($middleware === []) {
            return $this;
        }

        $clone = clone $this;
        $clone->middleware = [...$clone->middleware, ...$middleware];
        return $clone;
    }

    /**
     * Return a copy with a new route name.
     *
     * @param string $name
     *
     * @return self
     */
    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;
        return $clone;
    }
}
