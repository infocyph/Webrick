<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Closure;
use Infocyph\Webrick\Router\Constraint\Registry;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

/**
 * Hot-path DTO consumed by matchers.
 *
 * @psalm-type MiddlewareList = list<class-string|object>
 */
final class CompiledRoute implements RouteInterface
{
    /** Auto-incrementing ordinal used by FastRegexCompiler for stable capture-indices. */
    private static int $autoIdx = 0;

    /** @var callable|class-string */
    private $handler;

    /** Deterministic declaration order (starts at 0) */
    private readonly int $index;

    /**
     * Create a fully-compiled instance from a **mutable** {@see RouteInterface}.
     * All heavy work (regex building, param extraction, …) is done once here,
     * not on every match.
     */
    public static function fromRoute(RouteInterface $route): self
    {
        [$regex, $vars, $dyn] = self::compilePattern($route->getPath());

        // NB: `getMiddleware()` vs `getMiddlewares()` – keep BC.
        $mw = \method_exists($route, 'getMiddlewares')
            ? $route->getMiddlewares()
            : $route->getMiddleware();

        $corsPolicy = \method_exists($route, 'getCorsPolicy') ? $route->getCorsPolicy() : null;

        $idx = self::$autoIdx++;

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
            $idx,
            $corsPolicy
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
        int                        $index,
        private readonly ?Cors    $corsPolicy = null,
    ) {
        $this->handler = $handler;
        $this->index   = $index;
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

    public function getCorsPolicy(): ?Cors
    {
        return $this->corsPolicy;
    }

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

    /** Declaration order — used by FastRegexCompiler */
    public function getIndex(): int
    {
        return $this->index;
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
            $this->index,
            $this->corsPolicy
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
            $this->index,
            $this->corsPolicy
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
            $this->index,
            $this->corsPolicy
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
            return ['#\A' . preg_quote($path, '#') . '\z#D', [], false];
        }

        $segments   = explode('/', trim($path, '/'));
        $vars       = [];
        $patternBuf = [];

        foreach ($segments as $segment) {
            if ($segment === '') {      // leading / trailing /
                continue;
            }

            if (
                preg_match(
                    '/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}$/',
                    $segment,
                    $m
                )
            ) {
                [, $name, $constraint] = $m + [null, null, null];
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

        return ['#\A/' . implode('/', $patternBuf) . '\z#D', $vars, true];
    }
}
