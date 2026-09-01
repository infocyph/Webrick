<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Compact matcher backed by the compiled matcher IR. */
final class FusedMatcher extends AbstractMatcher implements MatcherInterface
{
    use MatcherCacheLifecycleTrait;
    use MatcherFactoryTrait;

    private const int INDEX_CACHE_VERSION = 11;

    /** @var array<string,array{0:string,1:?string}> */
    private array $alias = [];

    private bool $cacheEnabled = false;

    private string $cacheFile = '';

    private bool $cacheLoaded = false;

    private bool $cacheWriteEnabled = false;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $compiledHosts = null;

    private CompiledMatcherEngine $engine;

    private CompiledMatcherFastEngine $fastEngine;

    private bool $finalized = false;

    private CanonicalMatcherIndex $index;

    /** @var array<string,true> */
    private array $middlewareRequirements = [];

    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $this->bootIndex();
        $host = $this->canonicalRouteHost($route->getDomain());
        $this->index->add($host, $route);
        matcher_capture_route_alias($this->alias, $route);
        matcher_capture_middleware_requirements($this->middlewareRequirements, $route);
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->bootIndex();
        if ($this->cacheEnabled && $this->cacheWriteEnabled && !$this->index->isEmpty()) {
            $this->dumpCache();
        }
        if ($this->cacheEnabled && is_file($this->cacheFile)) {
            $this->loadCacheBlob();
        }
        if ($this->compiledHosts === null) {
            $this->compiledHosts = new CompiledMatcherIndexCompiler()->compile($this->index->hosts());
        }

        $this->finalized = true;
    }

    public function match(string $method, string $host, string $path): array
    {
        $verb = HttpMethodEnum::normalize($method);
        if ($verb === '') {
            throw new \InvalidArgumentException('HTTP method must be non-empty.');
        }
        $path = $path === '' ? '/' : $path;
        $outcome = $this->matchOutcome($verb, strtolower($host ?: '*'), $path);

        if ($outcome->type === MatchOutcomeType::FOUND) {
            return [$outcome->requireRoute(), $outcome->params];
        }
        if ($outcome->type === MatchOutcomeType::METHOD_NOT_ALLOWED || $outcome->type === MatchOutcomeType::AUTO_OPTIONS) {
            throw new MethodNotAllowedException($verb, $path, $outcome->allowed);
        }

        throw new RouteNotFoundException($verb, $path);
    }

    public function matchCompiled(string $method, string $host, string $path): int|array|MatchOutcome
    {
        $hosts = $this->compiledHosts ?? throw new \LogicException('Fused matcher must be finalized before compiled dispatch.');

        return $this->fastEngine->matchSingle(
            $hosts[$host] ?? null,
            $host !== '*' ? ($hosts['*'] ?? null) : null,
            $method,
            $path,
        );
    }

    public function matchOutcome(string $method, string $host, string $path): MatchOutcome
    {
        $this->finalize();
        $hosts = $this->compiledHosts ?? throw new \LogicException('Fused matcher was not compiled.');

        return $this->engine->matchSingle(
            $hosts[$host] ?? null,
            $host !== '*' ? ($hosts['*'] ?? null) : null,
            $method,
            $path,
        );
    }

    /** @return array{string,string|null}|null */
    public function resolveAlias(string $name): ?array
    {
        $index = $this->aliasIndex();

        return $index[$name] ?? null;
    }

    private function bootIndex(): void
    {
        $this->index ??= new CanonicalMatcherIndex();
        $this->engine ??= new CompiledMatcherEngine();
        $this->fastEngine ??= new CompiledMatcherFastEngine();
    }

    /**
     * @param array<array-key,mixed> $hosts
     * @param array<array-key,mixed> $alias
     * @param array<array-key,mixed> $middleware
     */
    private function cacheHash(array $hosts, array $alias, array $middleware): string
    {
        return hash('xxh128', serialize([$hosts, $alias, $middleware]));
    }

    private function dumpCache(): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir {$dir}");
        }

        $compiled = new CompiledMatcherIndexCompiler()->compile($this->index->hosts());
        $hosts = MatcherCachePayloadNormalizer::normalize($compiled);
        $hosts = CompiledMatcherIndexValidator::validateHosts($hosts);
        $middleware = array_keys($this->middlewareRequirements);
        $hash = $this->cacheHash($hosts, $this->alias, $middleware);
        $php = "<?php\nreturn [\n"
            . "    '_version' => " . self::INDEX_CACHE_VERSION . ",\n"
            . "    '_hash' => " . var_export($hash, true) . ",\n"
            . "    '_data' => " . $this->exportArray($hosts) . ",\n"
            . "    '_alias' => " . $this->exportArray($this->alias) . ",\n"
            . "    '_middleware' => " . $this->exportArray($middleware) . ",\n"
            . "];\n";

        matcher_write_validated_atomic_php_file(
            $this->cacheFile,
            $php,
            function (array $blob) use ($hash): void {
                if (($blob['_version'] ?? null) !== self::INDEX_CACHE_VERSION) {
                    throw new \UnexpectedValueException('Generated fused matcher cache has an invalid format version.');
                }
                $data = CompiledMatcherIndexValidator::validateHosts($blob['_data'] ?? null);
                $alias = $blob['_alias'] ?? null;
                $middleware = matcher_normalize_middleware_requirements($blob['_middleware'] ?? []);
                $stored = $blob['_hash'] ?? null;
                if (!is_array($alias) || !is_string($stored)) {
                    throw new \UnexpectedValueException('Generated fused matcher cache has an invalid payload.');
                }
                if (!hash_equals($hash, $stored) || !hash_equals($hash, $this->cacheHash($data, $alias, $middleware))) {
                    throw new \UnexpectedValueException('Generated fused matcher cache hash mismatch.');
                }
            },
        );

        if ($this->shouldWarmOpcache()) {
            opcache_compile_file($this->cacheFile);
        }
    }

    private function loadCacheBlob(): void
    {
        if ($this->cacheLoaded) {
            return;
        }

        /** @var array<string,mixed> $blob */
        $blob = require $this->cacheFile;
        if (($blob['_version'] ?? null) !== self::INDEX_CACHE_VERSION) {
            throw new \RuntimeException('Stale fused route cache. Rebuild the route cache.');
        }

        $data = CompiledMatcherIndexValidator::validateHosts($blob['_data'] ?? null);
        $alias = $blob['_alias'] ?? null;
        if (!is_array($alias)) {
            throw new \RuntimeException('Fused route cache has an invalid alias payload.');
        }

        if ($this->verifyCacheOnLoad) {
            $stored = $blob['_hash'] ?? null;
            $middleware = matcher_normalize_middleware_requirements($blob['_middleware'] ?? []);
            if (!is_string($stored) || !hash_equals($stored, $this->cacheHash($data, $alias, $middleware))) {
                throw new \RuntimeException('Fused route cache hash mismatch.');
            }
        }

        $this->compiledHosts = $data;
        $this->alias = matcher_normalize_alias_pairs($alias);
        $this->middlewareRequirements = array_fill_keys(
            matcher_normalize_middleware_requirements($blob['_middleware'] ?? []),
            true,
        );
        $this->cacheLoaded = true;
    }
}
