<?php

/*-------------------------------------------------------------------------*
 *  CompiledRoute – cache-friendly (PHP 8.4)                               *
 *-------------------------------------------------------------------------*/
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Closure;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

/**
 * @psalm-type SegmentSpec = array{type:'lit',val:string}|array{type:'var',name:string,regex:string}
 * @psalm-type MiddlewareList = list<class-string|object>
 */
#[\AllowDynamicProperties(false)]
final class CompiledRoute implements RouteInterface
{
    /*──────────────────────── static ordinal ───────────────────────*/
    private static int $autoIdx = 0;

    /*──────────────────────── ctor state ───────────────────────────*/
    /** @var callable|class-string|array */
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
        private readonly array $variables,
        private readonly int $index,
        private readonly ?Cors $corsPolicy,
        array $segments,
    ) {
        $this->handler = $handler;   // no binding / cloning
        $this->segments = $segments;
    }

    /*──────────────────── factory (compile-once) ───────────────────*/
    public static function fromRoute(RouteInterface $route): self
    {
        [$regex, $vars, $dynamic, $segments] = self::parsePath($route->getPath());

        $mw = $route->getMiddlewares() ?? $route->getMiddleware();
        $cors = \method_exists($route, 'getCorsPolicy') ? $route->getCorsPolicy() : null;

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

    public static function __set_state(array $data): self
    {
        return new self(...$data);
    }


    // (unchanged – all interface methods retained verbatim)
    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler(): callable|array|string
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
        return $this->path === '/' ? 0 : \substr_count($this->path, '/');
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    /*──────────────────── functional immutators ────────────────────*/
    public function withDomain(?string $domain): self
    {
        return new self(...$this->copyProps(domain: $domain));
    }

    /** @param MiddlewareList $middleware */
    public function withMiddleware(array $middleware): self
    {
        return new self(...$this->copyProps(middleware: [...$this->middleware, ...$middleware]));
    }

    public function withName(string $name): self
    {
        return new self(...$this->copyProps(name: $name));
    }

    /*──────────────────── private helpers ──────────────────────────*/
    private function copyProps(
        ?string $domain = null,
        ?array $middleware = null,
        ?string $name = null,
    ): array {
        return [
            $this->method,
            $this->path,
            $this->handler,
            $domain ?? $this->domain,
            $middleware ?? $this->middleware,
            $name ?? $this->name,
            $this->dynamic,
            $this->regex,
            $this->variables,
            $this->index,
            $this->corsPolicy,
            $this->segments,
        ];
    }

    /*────────────  path-pattern compilation  ───────────────────────*/
    private static function parsePath(string $path): array
    {
        return \str_contains($path, '{')
            ? self::parseDynamicPath($path)
            : self::parseStaticPath($path);
    }

    private static function parseStaticPath(string $path): array
    {
        $segments = [];
        foreach (\explode('/', \trim($path, '/')) as $seg) {
            if ($seg !== '') {
                $segments[] = ['type' => 'lit', 'val' => $seg];
            }
        }

        return [
            '#\A' . ($path === '/' ? '/' : self::quoteIfNeeded($path)) . '\z#D',
            /* vars    */ [],
            /* dynamic */ false,
            $segments,
        ];
    }

    private static function parseDynamicPath(string $path): array
    {
        static $phRe = '/^\{([A-Za-z_]\w*)(?::([^}]+))?}$/';

        $segments = [];
        $vars = [];
        $patternBuf = [];

        foreach (\explode('/', \trim($path, '/')) as $raw) {
            if ($raw === '') {
                continue;
            }

            if (\preg_match($phRe, $raw, $m)) {
                [$full, $name, $constraint] = $m + [null, null, null];

                $body = $constraint
                    ? ConstraintRegistry::buildPattern($constraint)
                    : '[^/]+';

                $segments[] = ['type' => 'var', 'name' => $name, 'regex' => "#\\A{$body}\\z#D"];
                $vars[] = $name;
                $patternBuf[] = "({$body})";
                continue;
            }

            $segments[] = ['type' => 'lit', 'val' => $raw];
            $patternBuf[] = \preg_quote($raw, '#');
        }

        return [
            '#\A/' . \implode('/', $patternBuf) . '\z#D',
            $vars,
            /* dynamic */ true,
            $segments,
        ];
    }

    private static function quoteIfNeeded(string $s): string
    {
        return \strpbrk($s, '^$.[]|()?*+{}\\') !== false ? \preg_quote($s, '#') : $s;
    }
}
