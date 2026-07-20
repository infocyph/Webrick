<?php

/**
 * CompiledRoute
 *
 * Immutable, cache-friendly representation of a route produced from a
 * RouteInterface during compilation. This class stores a route's HTTP method,
 * path, handler, domain, middleware list, name and precomputed pattern data
 * (regex, variables, segments) suitable for fast matcher insertion and cache
 * emission. Callable constraints are represented as either a regex or a
 * callable-string and are preserved for runtime validation.
 *
 * This file aims to be safe for export into generated PHP cache blobs and
 * supports __set_state for rehydration.
 *
 * @author  Generated
 */
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;

/**
 * @psalm-type SegmentSpec =
 *   array{type:'lit',val:string}|
 *   array{type:'var',name:string,regex:string}|
 *   array{type:'var',name:string,call:callable-string}
 * @psalm-type MiddlewareList = list<string|object>
 *
 * Final, immutable compiled route used by matchers and cache writers.
 *
 * Responsibilities:
 *  - Hold precomputed pattern information (regex, variables, segments).
 *  - Provide stable accessors for route metadata.
 *  - Offer functional immutators that return new instances with modified
 *    domain, middleware or name while keeping other properties identical.
 *
 * Notes:
 *  - Instances are intended to be PHP-exportable; closures inside handlers are
 *    treated specially by matchers when producing cache blobs.
 */
final class CompiledRoute implements RouteInterface
{
    use RouteCoreAccessors;

    /* ──────────────────────── static ordinal ─────────────────────── */
    /**
     * Monotonic index assigned to each compiled route (used for stable ordering).
     */
    private static int $autoIdx = 0;

    /* ──────────────────────── ctor state ─────────────────────────── */

    /** @var array{0:object|string,1:string}|string|callable */
    private readonly mixed $handler;

    private readonly string $handlerId;

    /**
     * Construct a compiled route.
     *
     * The constructor is intentionally positional and used by fromRoute() and
     * by __set_state when rehydrating from cache blobs.
     *
     * @param string $method HTTP method (e.g. "GET")
     * @param string $path Original route path (absolute)
     * @param array{0:object|string,1:string}|string|callable $handler Route handler descriptor
     * @param string|null $domain Route domain or null (wildcard)
     * @param MiddlewareList $middleware List of middleware descriptors
     * @param string|null $name Route name or null
     * @param bool $dynamic True when route contains placeholders
     * @param string $regex Compiled full-route regex (anchored)
     * @param list<string> $variables List of variable names in order
     * @param int $index Stable numeric index
     * @param Cors|null $corsPolicy Optional CORS attribute instance
     * @param list<SegmentSpec> $segments Parsed segment specs (see SegmentSpec)
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        array|string|callable $handler,
        private readonly ?string $domain,
        private readonly array $middleware,
        private readonly ?string $name,
        private readonly bool $dynamic,
        private readonly string $regex,
        private readonly array $variables,
        private readonly int $index,
        private readonly ?Cors $corsPolicy,
        /**
         * Segment descriptors produced while parsing the route path.
         */
        private readonly array $segments,
    ) {
        $this->handler = $handler;
        $this->handlerId = Route::fingerprint($handler);
    }

    /* ──────────────────── accessors ─────────────────── */

    /**
     * Rehydrate instance from exported state (used by var_export/__set_state patterns).
     *
     * @param array{
     *   0:string,
     *   1:string,
     *   2:array{0:object|string,1:string}|string|callable,
     *   3:?string,
     *   4:list<string|object>,
     *   5:?string,
     *   6:bool,
     *   7:string,
     *   8:list<string>,
     *   9:int,
     *   10:?Cors,
     *   11:list<SegmentSpec>
     * } $data Positional arguments matching the constructor parameters
     * @return self Reconstructed CompiledRoute
     */
    public static function __set_state(array $data): self
    {
        return new self(...$data);
    }

    /* ──────────────────── factory (compile-once) ─────────────────── */

    /**
     * Create a CompiledRoute from a RouteInterface instance.
     *
     * This factory parses the route path into a regex, variable list and
     * segment specifications and copies middleware and other metadata.
     *
     * @param RouteInterface $route Source route to compile
     * @return self Compiled route instance
     */
    public static function fromRoute(RouteInterface $route): self
    {
        [$regex, $vars, $dynamic, $segments] = self::parsePath($route->getPath());

        $mw = $route->getMiddlewares();
        $cors = null;
        if (\method_exists($route, 'getCorsPolicy')) {
            $maybeCors = $route->getCorsPolicy();
            if ($maybeCors instanceof Cors) {
                $cors = $maybeCors;
            }
        }

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

    /**
     * Get the optional CORS policy attribute attached to the route.
     *
     * @return Cors|null CORS attribute instance or null
     */
    public function getCorsPolicy(): ?Cors
    {
        return $this->corsPolicy;
    }

    /**
     * Stable numeric index assigned at creation time.
     *
     * @return int Monotonic index
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Compute an integer representing path length in segments.
     *
     * For '/', returns 0. Otherwise counts '/' occurrences.
     *
     * @return int Segment count metric for ordering/weighting
     */
    public function getPathLength(): int
    {
        return $this->path === '/' ? 0 : \substr_count($this->path, '/');
    }

    /**
     * Full anchored regex used to match the route at runtime.
     *
     * @return string Anchored PCRE regex (delimiter and anchors included)
     */
    public function getRegex(): string
    {
        return $this->regex;
    }

    /**
     * Return the parsed segment specifications for the route path.
     *
     * Each segment is either a literal spec or a var spec (see SegmentSpec type).
     *
     * @return list<SegmentSpec> Parsed path segments
     */
    public function getSegments(): array
    {
        return $this->segments;
    }

    /**
     * List of variable names in the order they appear in the path.
     *
     * @return list<string> Variable names
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * Whether the route contains dynamic placeholders.
     *
     * @return bool True when route contains placeholders like {id}
     */
    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    /* ──────────────────── functional immutators ──────────────────── */

    /**
     * Return a copy of this CompiledRoute with the given domain.
     *
     * Does not modify the current instance; returns a new instance with the
     * modified domain while preserving other properties.
     *
     * @param string|null $domain New domain or null
     * @return self New CompiledRoute with updated domain
     */
    public function withDomain(?string $domain): self
    {
        return new self(...$this->copyProps(domain: $domain));
    }

    /**
     * Return a copy of this CompiledRoute with additional middleware appended.
     *
     * @param MiddlewareList $middleware List of middleware descriptors to append
     * @return self New CompiledRoute with merged middleware
     */
    public function withMiddleware(array $middleware): self
    {
        return new self(...$this->copyProps(middleware: [...$this->middleware, ...$middleware]));
    }

    /**
     * Return a copy of this CompiledRoute with a new name.
     *
     * @param string $name New primary name for the route
     * @return self New CompiledRoute with updated name
     */
    public function withName(string $name): self
    {
        return new self(...$this->copyProps(name: $name));
    }

    /**
     * Assemble the final anchored route pattern from piece patterns.
     *
     * @param list<string> $patternBuf Unanchored piece patterns (literals or captures)
     * @return string Anchored PCRE regex matching the full path
     */
    private static function buildAnchoredPattern(array $patternBuf): string
    {
        return '#\A/' . \implode('/', $patternBuf) . '\z#D';
    }

    /**
     * Build a variable segment specification and the corresponding unanchored
     * piece pattern used when assembling the full route regex.
     *
     * Behaviour:
     *  - When a named constraint resolves to a regex, that inner regex is used
     *    both for the per-segment validation regex and as the capture group.
     *  - When the constraint resolves to a callable, the piece pattern remains
     *    permissive '([^/]+)' and the callable will be invoked at match-time.
     *  - When no constraint is provided, the default "[^/]+" is used.
     *
     * @param non-empty-string $name Placeholder variable name
     * @param ?non-empty-string $constraint Constraint token or null
     * @return array{0:SegmentSpec,1:string} [segmentSpec, piecePattern]
     */
    private static function buildVarSegment(string $name, ?string $constraint): array
    {
        if ($constraint !== null) {
            $spec = ConstraintRegistry::getValidatorSpec($constraint);

            if (isset($spec['regex'])) {
                // regex provides inner body (no anchors) — wrap for segment validation
                $inner = $spec['regex']; // inner body, no anchors

                return [
                    ['type' => 'var', 'name' => $name, 'regex' => "#\\A{$inner}\\z#D"],
                    "({$inner})",
                ];
            }

            /** @var callable-string $call */
            $call = $spec['callable'];

            // Callable constraints are deferred to runtime; pattern remains permissive.
            return [
                ['type' => 'var', 'name' => $name, 'call' => $call],
                '([^/]+)',
            ];
        }

        // No constraint → default segment matcher.
        return [
            ['type' => 'var', 'name' => $name, 'regex' => '#\\A[^/]+\\z#D'],
            '([^/]+)',
        ];
    }

    /* ──────────── small utilities & splits ──────────── */

    /**
     * Convert a static path into a list of literal segment specs.
     *
     * @param string $path Input path
     * @return list<SegmentSpec> List of literal segment specifications
     */
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

    /**
     * Split a raw path into raw segments (preserves placeholders).
     *
     * @param string $path Input path
     * @return list<string> Raw segment strings
     */
    private static function explodeRawSegments(string $path): array
    {
        return \explode('/', \trim($path, '/'));
    }

    /**
     * Parse a dynamic path containing placeholders into regex and segment specs.
     *
     * @param string $path Dynamic route path
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

            if (($placeholder = self::parsePlaceholder($raw)) !== null) {
                [$name, $constraint] = $placeholder;
                $vars[] = $name;

                [$segSpec, $pieceRegex] = self::buildVarSegment($name, $constraint);
                $segments[] = $segSpec;
                $patternBuf[] = $pieceRegex; // capture group for variable segment

                continue;
            }

            // literal segment: add literal spec and quoted piece for pattern
            $segments[] = ['type' => 'lit', 'val' => $raw];
            $patternBuf[] = \preg_quote($raw, '#');
        }

        $pattern = self::buildAnchoredPattern($patternBuf);

        return [$pattern, $vars, /* dynamic */ true, $segments];
    }

    /* ────────────  path-pattern compilation  ─────────────────────── */

    /**
     * Parse a path into either a static or dynamic compiled form.
     *
     * Returns a tuple:
     *  [0] string   => anchored route regex
     *  [1] list     => variable name list in order
     *  [2] bool     => dynamic flag (true when placeholders present)
     *  [3] list     => SegmentSpec list describing each segment
     *
     * @param string $path Route path to parse
     * @return array{0:string,1:list<string>,2:bool,3:list<SegmentSpec>}
     */
    private static function parsePath(string $path): array
    {
        return \str_contains($path, '{')
            ? self::parseDynamicPath($path)
            : self::parseStaticPath($path);
    }

    /**
     * Parse "{name[:constraint]}" placeholder syntax in one regex pass.
     *
     * @param string $raw Segment text.
     * @return array{0:non-empty-string,1:?non-empty-string}|null [name,constraint] or null when not placeholder.
     */
    private static function parsePlaceholder(string $raw): ?array
    {
        static $phRe = '/^\{([A-Za-z_]\w*)(?::([^}]+))?}$/';
        if (!\is_string($phRe)) {
            return null;
        }
        if (\preg_match($phRe, $raw, $m) !== 1) {
            return null;
        }

        /** @var non-empty-string $name */
        $name = $m[1];

        /** @var ?non-empty-string $constraint */
        $constraint = isset($m[2]) && $m[2] !== '' ? $m[2] : null;

        return [$name, $constraint];
    }

    /**
     * Parse a purely static path (no placeholders).
     *
     * @param string $path Static route path
     * @return array{0:string,1:list<string>,2:false,3:list<SegmentSpec>}
     */
    private static function parseStaticPath(string $path): array
    {
        $segments = self::explodeLiterals($path);

        $pattern = '#\A' . ($path === '/' ? '/' : self::quoteIfNeeded($path)) . '\z#D';

        return [$pattern, /* vars */ [], /* dynamic */ false, $segments];
    }

    /**
     * Quote a path when it contains regex-special characters; otherwise return as-is.
     *
     * @param string $s Input string
     * @return string Quoted string if needed
     */
    private static function quoteIfNeeded(string $s): string
    {
        return \strpbrk($s, '^$.[]|()?*+{}\\') !== false ? \preg_quote($s, '#') : $s;
    }

    /* ──────────────────── private helpers ────────────────────────── */

    /**
     * Helper that assembles constructor positional args for copy-on-write operations.
     *
     * @param string|null $domain Optional override for domain
     * @param MiddlewareList|null $middleware Optional override for middleware list
     * @param string|null $name Optional override for name
     * @return array{
     *   0:string,
     *   1:string,
     *   2:array{0:object|string,1:string}|string|callable,
     *   3:?string,
     *   4:list<string|object>,
     *   5:?string,
     *   6:bool,
     *   7:string,
     *   8:list<string>,
     *   9:int,
     *   10:?Cors,
     *   11:list<SegmentSpec>
     * } Positional constructor argument list matching __construct signature
     */
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
}
