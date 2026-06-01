<?php

/**
 * Webrick - Declarative route definition.
 *
 * Represents a single HTTP route as produced by the Registrar, including its HTTP
 * method, path template, handler specification, optional domain/name, CORS policy,
 * and middleware stack. Provides immutable "with*" methods for safe modifications.
 */

/* src/Router/Route/Route.php */

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

/**
 * Declarative route definition produced by the Registrar.
 *
 * @psalm-type MiddlewareList = list<string|object>
 */
final class Route implements RouteInterface
{
    use RouteCoreAccessors;

    /**
     * One-time, stable fingerprint for the handler, used for fast lookups.
     */
    private readonly string $handlerId;

    /** @var Cors|null The route-specific CORS policy. */
    private ?Cors $corsPolicy = null;

    /** @var array{0:object|string,1:string}|string|callable */
    private mixed $handler;

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
     * @param array{0:object|string,1:string}|string|callable $handler Route handler specification.
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
     * @param array{0:object|string,1:string}|string|callable $h Handler specification.
     * @return string Stable identifier for hashing and lookups.
     */
    public static function fingerprint(array|string|callable $h): string
    {
        if (\is_string($h)) {
            return $h;
        }

        if (\is_array($h)) {
            $target = $h[0];
            $method = $h[1];
            $targetClass = \is_object($target) ? $target::class : $target;

            return $targetClass . '::' . $method;
        }

        if ($h instanceof \Closure) {
            return 'closure@' . \spl_object_id($h);
        }

        return \is_object($h) ? $h::class . '::__invoke' : 'callable';
    }

    /**
     * Get the route-specific CORS policy.
     */
    public function getCorsPolicy(): ?Cors
    {
        return $this->corsPolicy;
    }

    /**
     * Whether the path contains dynamic parameters (e.g., "{id}").
     */
    public function isDynamic(): bool
    {
        return str_contains($this->path, '{');
    }

    /**
     * Return a copy with a route-specific CORS policy.
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
     * @param list<string|object> $middleware
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
     */
    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }
}
