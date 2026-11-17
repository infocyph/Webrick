<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Route;

use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Constraint\Registry as ConstraintRegistry;
use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;

/**
 * @psalm-type SegmentSpec = array{type:'lit',val:string}|array{type:'var',name:string,regex:string}|array{type:'var',name:string,call:callable-string}
 * @psalm-type MiddlewareList = list<string|object|array{0:object|string,1:string}>
 */
final class CompiledRoute implements RouteInterface
{
    use RouteCoreAccessors;

    public const int CACHE_PAYLOAD_VERSION = 2;

    private const string PLACEHOLDER_REGEX = '/^\{([A-Za-z_]\w*)(?::([^}]+))?}$/';

    private static int $autoIdx = 0;

    /** @var array{0:object|string,1:string}|string|callable */
    private readonly mixed $handler;

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
    }

    /**
     * @param array<mixed> $data
     * @throws \UnexpectedValueException
     */
    public static function __set_state(array $data): self
    {
        return new self(
            method: self::stateString($data['method'] ?? null),
            path: self::stateString($data['path'] ?? null),
            handler: self::stateHandler($data['handler'] ?? null),
            domain: self::stateNullableString($data['domain'] ?? null),
            middleware: self::stateMiddleware($data['middleware'] ?? null),
            name: self::stateNullableString($data['name'] ?? null),
            dynamic: self::stateBool($data['dynamic'] ?? null),
            regex: self::stateString($data['regex'] ?? null),
            variables: self::stateStringList($data['variables'] ?? null),
            index: self::stateInt($data['index'] ?? null),
            corsPolicy: self::stateCors($data['corsPolicy'] ?? null),
            produces: self::stateProduces($data['produces'] ?? null),
            segments: self::stateSegments($data['segments'] ?? null),
        );
    }

    /**
     * @param array<mixed> $payload
     * @throws \UnexpectedValueException
     */
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

    #[\Override]
    public function getHandlerId(): string
    {
        return Route::fingerprint($this->handler);
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

    /**
     * @return array<mixed>
     * @throws \LogicException
     */
    public function toCachePayload(): array
    {
        $handler = $this->handler;
        if (is_string($handler)) {
            return $this->toCachePayloadWithHandler($handler);
        }
        if (!is_array($handler) || !is_string($handler[0])) {
            throw new \LogicException('Object-backed route handlers cannot use scalar cache payloads.');
        }

        return $this->toCachePayloadWithHandler([$handler[0], $handler[1]]);
    }

    /**
     * @param array{0:string,1:string}|string $handler
     * @return array<mixed>
     * @throws \LogicException
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
                'origins' => $cors->origins,
                'methods' => $cors->methods,
                'headers' => $cors->headers,
                'exposeHeaders' => $cors->exposeHeaders,
                'maxAgeSeconds' => $cors->maxAgeSeconds,
                'allowCredentials' => $cors->allowCredentials,
                'allowPrivateNetwork' => $cors->allowPrivateNetwork,
            ] : null,
            $produces instanceof Produces ? [
                'types' => $produces->types,
                'charsets' => $produces->charsets,
            ] : null,
            $this->segments,
        ];
    }

    #[\Override]
    public function withDomain(?string $domain): self
    {
        return $this->copyWith(domain: $domain);
    }

    /** @param MiddlewareList $middleware */
    #[\Override]
    public function withMiddleware(array $middleware): self
    {
        return $this->copyWith(middleware: [...$this->middleware, ...$middleware]);
    }

    #[\Override]
    public function withName(string $name): self
    {
        return $this->copyWith(name: $name);
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
     * @throws \InvalidArgumentException
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

    /** @return list<SegmentSpec> */
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
     * @return array{0:string,1:list<string>,2:true,3:list<SegmentSpec>}
     * @throws \InvalidArgumentException
     */
    private static function parseDynamicPath(string $path): array
    {
        $vars = [];
        $seenVars = [];
        $segments = [];
        $patternBuf = [];

        foreach (explode('/', trim($path, '/')) as $raw) {
            if ($raw === '') {
                continue;
            }

            $placeholder = self::parsePlaceholder($raw);
            if ($placeholder !== null) {
                [$name, $constraint] = $placeholder;
                if (isset($seenVars[$name])) {
                    throw new \InvalidArgumentException("Duplicate route parameter '{$name}' in path '{$path}'.");
                }
                $seenVars[$name] = true;
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

    /** @return array{0:string,1:list<string>,2:bool,3:list<SegmentSpec>} */
    private static function parsePath(string $path): array
    {
        return str_contains($path, '{') ? self::parseDynamicPath($path) : self::parseStaticPath($path);
    }

    /** @return array{0:non-empty-string,1:?non-empty-string}|null */
    private static function parsePlaceholder(string $raw): ?array
    {
        if (preg_match(self::PLACEHOLDER_REGEX, $raw, $matches) !== 1) {
            return null;
        }

        /** @var non-falsy-string $name */
        $name = $matches[1];
        /** @var ?non-empty-string $constraint */
        $constraint = $matches[2] ?? null;

        return [$name, $constraint];
    }

    /** @return array{0:string,1:list<string>,2:false,3:list<SegmentSpec>} */
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
     * @throws \UnexpectedValueException
     */
    private static function stateBool(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \UnexpectedValueException('Invalid dynamic flag in compiled route state.');
        }

        return $value;
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function stateCors(mixed $value): ?Cors
    {
        if ($value === null || $value instanceof Cors) {
            return $value;
        }

        throw new \UnexpectedValueException('Invalid CORS policy in compiled route state.');
    }

    /**
     * @return array{object|string,string}|string|callable
     * @throws \UnexpectedValueException
     */
    private static function stateHandler(mixed $value): array|string|callable
    {
        if (is_string($value) || is_callable($value)) {
            return $value;
        }
        if (is_array($value) && count($value) === 2 && (is_object($value[0]) || is_string($value[0])) && is_string($value[1])) {
            return [$value[0], $value[1]];
        }

        throw new \UnexpectedValueException('Invalid handler in compiled route state.');
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function stateInt(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \UnexpectedValueException('Invalid route index in compiled route state.');
        }

        return $value;
    }

    /**
     * @return MiddlewareList
     * @throws \UnexpectedValueException
     */
    private static function stateMiddleware(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid middleware in compiled route state.');
        }
        $middleware = [];
        foreach ($value as $entry) {
            $middleware[] = self::stateHandler($entry);
        }

        return $middleware;
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function stateNullableString(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new \UnexpectedValueException('Invalid nullable string in compiled route state.');
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function stateProduces(mixed $value): ?Produces
    {
        if ($value === null || $value instanceof Produces) {
            return $value;
        }

        throw new \UnexpectedValueException('Invalid Produces policy in compiled route state.');
    }

    /**
     * @return list<SegmentSpec>
     * @throws \UnexpectedValueException
     */
    private static function stateSegments(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid segments in compiled route state.');
        }

        return CompiledRouteCachePayload::validate([
            self::CACHE_PAYLOAD_VERSION, 'GET', '/', '__state__', null, [], null, false, '', [], 0, null, null, $value,
        ])[13];
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function stateString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Invalid string in compiled route state.');
        }

        return $value;
    }

    /**
     * @return list<string>
     * @throws \UnexpectedValueException
     */
    private static function stateStringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid variables in compiled route state.');
        }
        $variables = [];
        foreach ($value as $entry) {
            $variables[] = self::stateString($entry);
        }

        return $variables;
    }

    /**
     * @param string|false|null $domain false means keep existing, null means clear
     * @param MiddlewareList|null $middleware
     */
    private function copyWith(
        string|false|null $domain = false,
        ?array $middleware = null,
        ?string $name = null,
    ): self {
        return new self(
            method: $this->method,
            path: $this->path,
            handler: $this->handler,
            domain: $domain === false ? $this->domain : $domain,
            middleware: $middleware ?? $this->middleware,
            name: $name ?? $this->name,
            dynamic: $this->dynamic,
            regex: $this->regex,
            variables: $this->variables,
            index: $this->index,
            corsPolicy: $this->corsPolicy,
            produces: $this->produces,
            segments: $this->segments,
        );
    }
}
