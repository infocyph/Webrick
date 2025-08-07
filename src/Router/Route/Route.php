<?php

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
    /** @var MiddlewareList */
    private array $middleware = [];

    /** @var callable */
    private $handler;

    /** one-time, stringable fingerprint for fast look-ups */
    private readonly string $handlerId;

    /** @var Cors|null The route-specific CORS policy. */
    private ?Cors $corsPolicy = null;

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

    /* ---------------------------------------------------------- getters */

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler(): array|string|callable
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

    public function getMiddlewares(): array
    {
        return $this->middleware;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /** Fast key used by Collection */
    public function getHandlerId(): string
    {
        return $this->handlerId;
    }

    public function getCorsPolicy(): ?Cors
    {
        return $this->corsPolicy;
    }

    public function isDynamic(): bool
    {
        return str_contains($this->path, '{');
    }

    /* ---------------------------------------------------------- immutable setters (carry id unchanged) */

    public function withDomain(?string $domain): self
    {
        $clone = clone $this;
        $clone->domain = $domain;
        return $clone;
    }

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

    public function withCorsPolicy(Cors $policy): self
    {
        $clone = clone $this;
        $clone->corsPolicy = $policy;
        return $clone;
    }

    /* ---------------------------------------------------------- internals */

    /** Turn any callable spec into a stable scalar id. */
    public static function fingerprint(array|string|callable $h): string
    {
        return match (true) {
            \is_string($h) => $h,                //  "function"  or  "Cls::method"
            \is_array($h) => (is_object($h[0]) ? $h[0]::class : $h[0]) . '::' . $h[1],
            \is_object($h) && !($h instanceof \Closure) => $h::class . '::__invoke',
            default => 'closure@' . \spl_object_id($h),
        };
    }
}
