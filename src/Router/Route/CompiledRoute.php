<?php

/*-------------------------------------------------------------------------*
 *  CompiledRoute – PHP 8.4 tuned                                          *
 *-------------------------------------------------------------------------*/
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Closure;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

#[\AllowDynamicProperties(false)]
final class CompiledRoute implements RouteInterface
{
    /** auto-incrementing ordinal (declaration order) */
    private static int $autoIdx = 0;

    /** @var callable|class-string */
    private mixed $handler;

    /** @var list<SegmentSpec> */
    private array $segments;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        callable|Closure|string|array $handler,
        private readonly ?string $domain,
        private readonly array $middleware,
        private readonly ?string $name,
        private readonly bool $dynamic,
        private readonly string $regex,
        private readonly array $variables,   // ordered capture names
        private readonly int $index,
        private readonly ?Cors $corsPolicy,
        array $segments,    // <── new
    ) {
        // tiny win: keep the handler as given (no extra Closure bind/copy)
        $this->handler = $handler;
        $this->segments = $segments;
    }

    /*──────────────────── factory ────────────────────*/

    /**
     * Parse & compile exactly once per route declaration.
     */
    public static function fromRoute(RouteInterface $route): self
    {
        [$regex, $vars, $dynamic, $segments] = self::parsePath($route->getPath());

        // direct property promotion avoids two reflection calls
        $mw = $route->getMiddlewares() ?? $route->getMiddleware();
        $cors = \method_exists($route, 'getCorsPolicy')
            ? $route->getCorsPolicy() : null;

        return new self(
            method: $route->getMethod(),
            path: $route->getPath(),
            handler: $route->getHandler(),
            domain: $route->getDomain(),
            middleware: $mw,
            name: $route->getName(),
            dynamic: $dynamic,
            regex: $regex,
            variables: $vars,
            index: self::$autoIdx++,
            corsPolicy: $cors,
            segments: $segments,
        );
    }

    /*──────────────────── accessors ───────────────────*/

    // (unchanged – all interface methods retained verbatim)
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
    } // BC

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

    /** @return list<SegmentSpec> */
    public function getSegments(): array
    {
        return $this->segments;
    }

    public function getPathLength(): int
    {
        // micro-opt: avoid trim() when not needed
        return $this->path === '/' ? 0 : substr_count($this->path, '/');
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    /*──────────────── functional mutators (immutable) ────────────────*/

    public function withDomain(?string $domain): self
    {
        return new self(...$this->copyProps(domain: $domain));
    }

    /** @param list<class-string|object> $middleware */
    public function withMiddleware(array $middleware): self
    {
        return new self(
            ...$this->copyProps(
                middleware: [...$this->middleware, ...$middleware],
            ),
        );
    }

    public function withName(string $name): self
    {
        return new self(...$this->copyProps(name: $name));
    }

    /*──────────────── internal helper ───────────────────────────────*/

    /**
     * Tiny helper to build a new argument list without reflection.
     */
    private function copyProps(
        ?string $domain = null,
        ?array $middleware = null,
        ?string $name = null,
    ): array {
        return [
            $this->method,               // 0
            $this->path,                 // 1
            $this->handler,              // 2
            $domain ?? $this->domain,// 3
            $middleware ?? $this->middleware, // 4
            $name ?? $this->name,  // 5
            $this->dynamic,              // 6
            $this->regex,                // 7
            $this->variables,            // 8
            $this->index,                // 9
            $this->corsPolicy,           //10
            $this->segments,             //11
        ];
    }

    /**
     * Parses a URI pattern once; returns regex + metadata.
     *
     * @psalm-return array{0:string,1:list<string>,2:bool,3:list<SegmentSpec>}
     */
    /**
     * Compile a route pattern once.
     *
     * @psalm-return array{0:string,1:list<string>,2:bool,3:list<SegmentSpec>}
     */
    private static function parsePath(string $path): array
    {
        return str_contains($path, '{')
            ? self::parseDynamicPath($path)
            : self::parseStaticPath($path);
    }

    /*──────────── 1. Static pattern (no placeholders) ─────────────────────*/
    private static function parseStaticPath(string $path): array
    {
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $seg) {
            if ($seg !== '') {
                $segments[] = ['type' => 'lit', 'val' => $seg];
            }
        }

        return [
            '#\A' . ($path === '/' ? '/' : self::quoteIfNeeded($path)) . '\z#D',
            /*vars*/ [],
            /*dynamic*/ false,
            $segments,
        ];
    }

    /*──────────── 2. Dynamic pattern (with {placeholders}) ────────────────*/
    private static function parseDynamicPath(string $path): array
    {
        static $phRe = '/^\{([A-Za-z_]\w*)(?::([^}]+))?}$/';

        $segments = [];
        $vars = [];
        $patternBuf = [];

        foreach (explode('/', trim($path, '/')) as $raw) {
            if ($raw === '') {
                continue;
            }

            if (preg_match($phRe, $raw, $m)) {                 // variable
                $name = $m[1];
                $constraint = $m[2] ?? null;

                $body = $constraint
                    ? ConstraintRegistry::buildPattern($constraint)
                    : '[^/]+';

                $segments[] = [
                    'type' => 'var',
                    'name' => $name,
                    'regex' => "#\\A$body\\z#D",
                ];
                $vars[] = $name;
                $patternBuf[] = "({$body})";
                continue;
            }

            $segments[] = ['type' => 'lit', 'val' => $raw];
            $patternBuf[] = preg_quote($raw, '#');
        }

        return [
            '#\A/' . implode('/', $patternBuf) . '\z#D',
            $vars,
            /*dynamic*/ true,
            $segments,
        ];
    }

    /*──────────── 3. Helper – quote only when meta-chars present ──────────*/
    private static function quoteIfNeeded(string $s): string
    {
        return strpbrk($s, '^$.[]|()?*+{}\\') !== false ? preg_quote($s, '#') : $s;
    }


}
