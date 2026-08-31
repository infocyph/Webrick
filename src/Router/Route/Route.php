<?php

/**
 * Webrick - Declarative route definition.
 *
 * Represents a single HTTP route as produced by the Registrar, including its HTTP
 * method, path template, handler specification, optional domain/name, compiled
 * response metadata, and middleware stack.
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

/**
 * Declarative route definition produced by the Registrar.
 *
 * @psalm-type MiddlewareList = list<string|object>
 */
final class Route implements RouteInterface
{
    use RouteCoreAccessors;

    /** One-time, stable fingerprint for the handler. */
    private readonly string $handlerId;

    /** @var Cors|null The route-specific CORS policy. */
    private ?Cors $corsPolicy = null;

    /** @var array{0:object|string,1:string}|string|callable */
    private mixed $handler;

    /** @var MiddlewareList */
    private array $middleware = [];

    private ?Produces $produces = null;

    /**
     * @param array{0:object|string,1:string}|string|callable $handler
     * @param string $method
     * @param string $path
     * @param ?string $domain
     * @param ?string $name
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

    /**
     * @param array{0:object|string,1:string}|string|callable $h
     */
    public static function fingerprint(array|string|callable $h): string
    {
        if (is_string($h)) {
            return $h;
        }
        if (is_array($h)) {
            $target = $h[0];
            $method = $h[1];
            $targetClass = is_object($target) ? $target::class : $target;

            return $targetClass . '::' . $method;
        }
        if ($h instanceof \Closure) {
            return 'closure@' . spl_object_id($h);
        }

        return is_object($h) ? $h::class . '::__invoke' : 'callable';
    }

    public function getCorsPolicy(): ?Cors
    {
        return $this->corsPolicy;
    }

    public function getProduces(): ?Produces
    {
        return $this->produces;
    }

    public function isDynamic(): bool
    {
        return str_contains($this->path, '{');
    }

    public function withCorsPolicy(Cors $policy): self
    {
        $clone = clone $this;
        $clone->corsPolicy = $policy;

        return $clone;
    }

    public function withDomain(?string $domain): self
    {
        $clone = clone $this;
        $clone->domain = $domain;

        return $clone;
    }

    /** @param list<string|object> $middleware */
    public function withMiddleware(array $middleware): self
    {
        if ($middleware === []) {
            return $this;
        }

        $clone = clone $this;
        $clone->middleware = [...$clone->middleware, ...$middleware];

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withProduces(Produces $produces): self
    {
        $clone = clone $this;
        $clone->produces = $produces;

        return $clone;
    }
}
