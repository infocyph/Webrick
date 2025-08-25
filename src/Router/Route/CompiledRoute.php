<?php

/*-------------------------------------------------------------------------*
 *  CompiledRoute – cache-friendly (PHP 8.4) with callable constraints     *
 *-------------------------------------------------------------------------*/
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Closure;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

/**
 * @psalm-type SegmentSpec =
 *   array{type:'lit',val:string}|
 *   array{type:'var',name:string,regex:string}|
 *   array{type:'var',name:string,call:callable-string}
 *
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
//        if (\is_object($this->handler)) {
//            $class = $this->handler::class;
//            if ($class === 'Opis\\Closure\\Box' || \is_subclass_of($this->handler, 'Opis\\Closure\\Box')) {
//                return $this->handler->get();
//            }
//        }
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

    /**
     * @return array{0:string,1:list<string>,2:bool,3:list<SegmentSpec>}
     */
    private static function parsePath(string $path): array
    {
        return \str_contains($path, '{')
            ? self::parseDynamicPath($path)
            : self::parseStaticPath($path);
    }

    /**
     * @return array{0:string,1:list<string>,2:false,3:list<SegmentSpec>}
     */
    private static function parseStaticPath(string $path): array
    {
        $segments = self::explodeLiterals($path);

        $pattern = '#\A' . ($path === '/' ? '/' : self::quoteIfNeeded($path)) . '\z#D';

        return [$pattern, /*vars*/ [], /*dynamic*/ false, $segments];
    }

    /**
     * @return array{0:string,1:list<string>,2:true,3:list<SegmentSpec>}
     */
    private static function parseDynamicPath(string $path): array
    {
        $rawSegs = self::explodeRawSegments($path);

        $vars = [];
        $segments = [];
        $patternBuf = [];

        foreach ($rawSegs as $raw) {
            if ($raw === '') {
                continue;
            }

            if (self::isPlaceholder($raw)) {
                [$name, $constraint] = self::extractPlaceholder($raw);
                $vars[] = $name;

                [$segSpec, $pieceRegex] = self::buildVarSegment($name, $constraint);
                $segments[] = $segSpec;
                $patternBuf[] = $pieceRegex; // capture group
                continue;
            }

            // literal
            $segments[] = ['type' => 'lit', 'val' => $raw];
            $patternBuf[] = \preg_quote($raw, '#');
        }

        $pattern = self::buildAnchoredPattern($patternBuf);

        return [$pattern, $vars, /*dynamic*/ true, $segments];
    }

    /*──────────── small utilities & splits ────────────*/

    /** @return list<SegmentSpec> */
    private static function explodeLiterals(string $path): array
    {
        $segments = [];
        foreach (\explode('/', \trim($path, '/')) as $seg) {
            if ($seg !== '') {
                $segments[] = ['type' => 'lit', 'val' => $seg];
            }
        }
        return $segments;
    }

    /** @return list<string> */
    private static function explodeRawSegments(string $path): array
    {
        return \explode('/', \trim($path, '/'));
    }

    private static function isPlaceholder(string $raw): bool
    {
        static $phRe = '/^\{([A-Za-z_]\w*)(?::([^}]+))?}$/';
        return (bool)\preg_match($phRe, $raw);
    }

    /**
     * @return array{0:non-empty-string,1:?non-empty-string}
     */
    private static function extractPlaceholder(string $raw): array
    {
        static $phRe = '/^\{([A-Za-z_]\w*)(?::([^}]+))?}$/';
        \preg_match($phRe, $raw, $m);
        /** @var non-empty-string $name */
        $name = (string)($m[1] ?? '');
        /** @var ?non-empty-string $constraint */
        $constraint = isset($m[2]) && $m[2] !== '' ? (string)$m[2] : null;
        return [$name, $constraint];
    }

    /**
     * Build a variable segment spec + its piece regex for the route pattern.
     *
     * - If constraint is regex: use inner regex for both segment check and pattern.
     * - If constraint is callable: store callable and keep pattern permissive `([^/]+)`.
     *
     * @param non-empty-string $name
     * @param ?non-empty-string $constraint
     * @return array{0:SegmentSpec,1:string} [segmentSpec, piecePattern]
     */
    private static function buildVarSegment(string $name, ?string $constraint): array
    {
        if ($constraint) {
            $spec = ConstraintRegistry::getValidatorSpec($constraint);

            if (isset($spec['regex'])) {
                $inner = $spec['regex']; // inner body, no anchors
                return [
                    ['type' => 'var', 'name' => $name, 'regex' => "#\\A{$inner}\\z#D"],
                    "({$inner})",
                ];
            }

            /** @var callable-string $call */
            $call = $spec['callable'];
            return [
                ['type' => 'var', 'name' => $name, 'call' => $call],
                '([^/]+)',
            ];
        }

        // No constraint → default “[ ^/ ]+”
        return [
            ['type' => 'var', 'name' => $name, 'regex' => "#\\A[^/]+\\z#D"],
            '([^/]+)',
        ];
    }

    /**
     * @param list<string> $patternBuf capture or literal piece patterns (no anchors)
     */
    private static function buildAnchoredPattern(array $patternBuf): string
    {
        return '#\A/' . \implode('/', $patternBuf) . '\z#D';
    }

    private static function quoteIfNeeded(string $s): string
    {
        return \strpbrk($s, '^$.[]|()?*+{}\\') !== false ? \preg_quote($s, '#') : $s;
    }
}
