<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Closure;
use Infocyph\Webrick\Router\Constraint\Registry;
use Infocyph\Webrick\Interfaces\RouteInterface;

/**
 * Hot-path DTO consumed by matchers.
 *
 * @psalm-type MiddlewareList = list<class-string|object>
 */
final class CompiledRoute implements RouteInterface
{
    /** @var callable|class-string */
    private $handler;

    /**
     * Create a fully-compiled instance from a **mutable** {@see RouteInterface}.
     * All heavy work (regex building, param extraction, …) is done once here,
     * not on every match.
     */
    public static function fromRoute(RouteInterface $route): self
    {
        [$regex, $vars, $dyn] = self::compilePattern($route->getPath());

        // NB: `getMiddleware()` vs `getMiddlewares()` – keep BC.
        $mw = method_exists($route, 'getMiddlewares')
            ? $route->getMiddlewares()
            : $route->getMiddleware();

        return new self(
            $route->getMethod(),
            $route->getPath(),
            $route->getHandler(),
            $route->getDomain(),
            $mw,
            $route->getName(),
            $dyn,
            $regex,
            $vars,
        );
    }

    /**
     * @internal Instantiation happens only via {@see fromRoute()}.
     *
     * @param MiddlewareList         $middleware
     * @param list<non-empty-string> $variables
     */
    public function __construct(
        private readonly string   $method,
        private readonly string   $path,
        callable|Closure|string   $handler,
        private readonly ?string  $domain,
        private readonly array    $middleware,
        private readonly ?string  $name,
        private readonly bool     $dynamic,
        private readonly string   $regex,
        private readonly array    $variables,
    ) {
        $this->handler = $handler;
    }

    /* -----------------------------------------------------------------
     *  Interface – accessors
     * ----------------------------------------------------------------*/

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
    public function getDomain(): ?string
    {
        return $this->domain;
    }
    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return MiddlewareList */
    public function getMiddlewares(): array
    {
        return $this->middleware;
    }

    /** Legacy alias kept for BC */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /* -----------------------------------------------------------------
     *  Matcher helpers
     * ----------------------------------------------------------------*/

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }
    public function getRegex(): string
    {
        return $this->regex;
    }

    /** @return list<non-empty-string> */
    public function getVariables(): array
    {
        return $this->variables;
    }

    public function getPathLength(): int
    {
        return $this->path === '/'
            ? 0
            : substr_count(trim($this->path, '/'), '/') + 1;
    }

    /* -----------------------------------------------------------------
     *  Functional mutators – return **NEW** instance
     * ----------------------------------------------------------------*/

    public function withDomain(?string $domain): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $domain,
            $this->middleware,
            $this->name,
            $this->dynamic,
            $this->regex,
            $this->variables,
        );
    }

    /** @param MiddlewareList $middleware */
    public function withMiddleware(array $middleware): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $this->domain,
            [...$this->middleware, ...$middleware],
            $this->name,
            $this->dynamic,
            $this->regex,
            $this->variables,
        );
    }

    public function withName(string $name): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $this->domain,
            $this->middleware,
            $name,
            $this->dynamic,
            $this->regex,
            $this->variables,
        );
    }

    /* -----------------------------------------------------------------
     *  Internal helpers
     * ----------------------------------------------------------------*/

    /**
     * Compile `{param:constraint}` placeholders into a PCRE and capture list.
     *
     * @return array{0:string,1:list<string>,2:bool}  [regex, vars, isDynamic]
     */
    private static function compilePattern(string $path): array
    {
        if (!str_contains($path, '{')) {
            // Fast-path for 100 % static URIs
            return ['#^' . preg_quote($path, '#') . '$#D', [], false];
        }

        $segments   = explode('/', trim($path, '/'));
        $vars       = [];
        $patternBuf = [];

        foreach ($segments as $segment) {
            if ($segment === '') {            // leading / trailing /
                continue;
            }

            if (
                preg_match(
                    '/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}$/',
                    $segment,
                    $m
                )
            ) {
                [$raw, $name, $constraint] = $m + [null, null, null];
                $regexPart = $constraint
                    ? Registry::buildPattern($constraint)
                    : '[^/]+';

                $patternBuf[] = '(' . $regexPart . ')';
                $vars[]       = $name;
                continue;
            }

            // literal piece
            $patternBuf[] = preg_quote($segment, '#');
        }

        return ['#^/' . implode('/', $patternBuf) . '$#D', $vars, true];
    }
}
