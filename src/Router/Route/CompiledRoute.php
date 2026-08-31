<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

/**
 * @psalm-type SegmentSpec = array{type:'lit',val:string}|array{type:'var',name:string,regex:string}|array{type:'var',name:string,call:callable-string}
 * @psalm-type MiddlewareList = list<string|object>
 */
final class CompiledRoute implements RouteInterface
{
    use RouteCoreAccessors;

    public const int CACHE_PAYLOAD_VERSION = 2;

    private static int $autoIdx = 0;

    /** @var array{0:object|string,1:string}|string|callable */
    private readonly mixed $handler;

    private readonly string $handlerId;

    /**
     * @param array{0:object|string,1:string}|string|callable $handler
     * @param MiddlewareList $middleware
     * @param list<string> $variables
     * @param list<SegmentSpec> $segments
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
        private readonly ?Produces $produces,
        private readonly array $segments,
    ) {
        $this->handler = $handler;
        $this->handlerId = Route::fingerprint($handler);
    }

    /** @param array<mixed> $data */
    public static function __set_state(array $data): self
    {
        return new self(...$data);
    }

    /** @param array<mixed> $payload */
    public static function fromCachePayload(array $payload): self
    {
        $data = CompiledRouteCachePayload::validate($payload);
        $cors = $data[11];
        $produces = $data[12];

        return new self(
            method: $data[1],
            path: $data[2],
            handler: $data[3],
            domain: $data[4],
            middleware: $data[5],
            name: $data[6],
            dynamic: $data[7],
            regex: $data[8],
            variables: $data[9],
            index: $data[10],
            corsPolicy: is_array($cors) ? new Cors(...$cors) : null,
            produces: is_array($produces) ? new Produces($produces['types'], $produces['charsets']) : null,
            segments: $data[13],
        );
    }

    public static function fromRoute(RouteInterface $route, ?int $index = null): self
    {
        [$regex, $vars, $dynamic, $segments] = self::parsePath($route->getPath());

        $cors = null;
        if (method_exists($route, 'getCorsPolicy')) {
            $maybeCors = $route->getCorsPolicy();
            if ($maybeCors instanceof Cors) {
                $cors = $maybeCors;
            }
        }

        $produces = null;
        if (method_exists($route, 'getProduces')) {
            $maybeProduces = $route->getProduces();
            if ($maybeProduces instanceof Produces) {
                $produces = $maybeProduces;
            }
        }

        return new self(
            method: $route->getMethod(),
            path: $route->getPath(),
            handler: $route->getHandler(),
            domain: $route->getDomain(),
            middleware: $route->getMiddlewares(),
            name: $route->getName(),
            dynamic: $dynamic,
            regex: $regex,
            variables: $vars,
            index: $index ?? self::$autoIdx++,
            corsPolicy: $cors,
            produces: $produces,
            segments: $segments,
        );
    }

    public function getCorsPolicy(): ?Cors
    {
        return $this->corsPolicy;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getPathLength(): int
    {
        return $this->path === '/' ? 0 : substr_count($this->path, '/');
    }

    public function getProduces(): ?Produces
    {
        return $this->produces;
    }

    public function getRegex(): string
    {
        return $this->regex;
    }

    /** @return list<SegmentSpec> */
    public function getSegments(): array
    {
        return $this->segments;
    }

    /** @return list<string> */
    public function getVariables(): array
    {
        return $this->variables;
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    /** @return array<mixed> */
    public function toCachePayload(): array
    {
        $handler = $this->handler;
        if (!is_string($handler) && (!is_array($handler) || !is_string($handler[0]))) {
            throw new \LogicException('Object-backed route handlers cannot use scalar cache payloads.');
        }

        return $this->toCachePayloadWithHandler($handler);
    }

    /**
     * @param array{0:string,1:string}|string $handler
     * @return array<mixed>
     */
    public function toCachePayloadWithHandler(array|string $handler): array
    {
        $middleware = [];
        foreach ($this->middleware as $entry) {
            if (!is_string($entry)) {
                throw new \LogicException('Object-backed route middleware cannot use scalar cache payloads.');
            }
            $middleware[] = $entry;
        }

        $cors = $this->corsPolicy;
        $produces = $this->produces;

        return [
            self::CACHE_PAYLOAD_VERSION,
            $this->method,
            $this->path,
            $handler,
            $this->domain,
            $middleware,
            $this->name,
            $this->dynamic,
            $this->regex,
            $this->variables,
            $this->index,
            $cors instanceof Cors ? [
                'origins' => array_values($cors->origins),
                'methods' => $cors->methods,
                'headers' => $cors->headers,
                'exposeHeaders' => $cors->exposeHeaders,
                'maxAgeSeconds' => $cors->maxAgeSeconds,
                'allowCredentials' => $cors->allowCredentials,
                'allowPrivateNetwork' => $cors->allowPrivateNetwork,
            ] : null,
            $produces instanceof Produces ? [
                'types' => array_values($produces->types),
                'charsets' => $produces->charsets === null ? null : array_values($produces->charsets),
            ] : null,
            $this->segments,
        ];
    }

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

    /** @param list<string> $patternBuf */
    private static function buildAnchoredPattern(array $patternBuf): string
    {
        return '#\A/' . implode('/', $patternBuf) . '\z#D';
    }

    /**
     * @param non-empty-string $name
     * @param ?non-empty-string $constraint
     * @return array{0:SegmentSpec,1:string}
     */
    private static function buildVarSegment(string $name, ?string $constraint): array
    {
        if ($constraint !== null) {
            $spec = ConstraintRegistry::getValidatorSpec($constraint);
            if (isset($spec['regex'])) {
                $inner = $spec['regex'];

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

        return [
            ['type' => 'var', 'name' => $name, 'regex' => '#\\A[^/]+\\z#D'],
            '([^/]+)',
        ];
    }

    /**
     * @return list<SegmentSpec>
     */
    private static function explodeLiterals(string $path): array
    {
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment !== '') {
                $segments[] = ['type' => 'lit', 'val' => $segment];
            }
        }

        return $segments;
    }

    /**
     * @return list<string>
     */
    private static function explodeRawSegments(string $path): array
    {
        return explode('/', trim($path, '/'));
    }

    /**
     * @return array{0:string,1:list<string>,2:true,3:list<SegmentSpec>}
     */
    private static function parseDynamicPath(string $path): array
    {
        $vars = [];
        $segments = [];
        $patternBuf = [];

        foreach (self::explodeRawSegments($path) as $raw) {
            if ($raw === '') {
                continue;
            }

            $placeholder = self::parsePlaceholder($raw);
            if ($placeholder !== null) {
                [$name, $constraint] = $placeholder;
                $vars[] = $name;
                [$segmentSpec, $pieceRegex] = self::buildVarSegment($name, $constraint);
                $segments[] = $segmentSpec;
                $patternBuf[] = $pieceRegex;

                continue;
            }

            $segments[] = ['type' => 'lit', 'val' => $raw];
            $patternBuf[] = preg_quote($raw, '#');
        }

        return [self::buildAnchoredPattern($patternBuf), $vars, true, $segments];
    }

    /**
     * @return array{0:string,1:list<string>,2:bool,3:list<SegmentSpec>}
     */
    private static function parsePath(string $path): array
    {
        return str_contains($path, '{') ? self::parseDynamicPath($path) : self::parseStaticPath($path);
    }

    /**
     * @return array{0:non-empty-string,1:?non-empty-string}|null
     */
    private static function parsePlaceholder(string $raw): ?array
    {
        static $placeholderRegex = '/^\{([A-Za-z_]\w*)(?::([^}]+))?}$/';
        if (preg_match($placeholderRegex, $raw, $matches) !== 1) {
            return null;
        }

        /** @var non-empty-string $name */
        $name = $matches[1];
        /** @var ?non-empty-string $constraint */
        $constraint = isset($matches[2]) && $matches[2] !== '' ? $matches[2] : null;

        return [$name, $constraint];
    }

    /**
     * @return array{0:string,1:list<string>,2:false,3:list<SegmentSpec>}
     */
    private static function parseStaticPath(string $path): array
    {
        $segments = self::explodeLiterals($path);
        $pattern = '#\A' . ($path === '/' ? '/' : self::quoteIfNeeded($path)) . '\z#D';

        return [$pattern, [], false, $segments];
    }

    private static function quoteIfNeeded(string $value): string
    {
        return strpbrk($value, '^$.[]|()?*+{}\\') !== false ? preg_quote($value, '#') : $value;
    }

    /**
     * @param MiddlewareList|null $middleware
     * @return array<mixed>
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
            $this->produces,
            $this->segments,
        ];
    }
}
