<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Sharded canonical-index matcher.
 *
 * @phpstan-type RouteValue CompiledRoute|array<array-key,mixed>|string
 * @phpstan-type VerbMap array<string,RouteValue>
 * @phpstan-type SegmentSpec array{type:'lit',val:string}|array{type:'var',name:string,regex:string}|array{type:'var',name:string,call:callable-string}
 * @phpstan-type DynamicEntry array{segments:list<SegmentSpec>,verbs:VerbMap}
 * @phpstan-type DynamicBuckets array<int,array<string,array<string,DynamicEntry>>>
 * @phpstan-type MatcherGroup array{static:array<string,VerbMap>,dynamic:DynamicBuckets}
 */
final class ShardedMatcher extends AbstractMatcher implements MatcherInterface
{
    use MatcherFactoryTrait;

    private const int INDEX_CACHE_VERSION = 6;

    private const string SHARD_DYNAMIC = '__dynamic';

    private const string SHARD_ROOT = '__root';

    /** @var list<string> */
    private const array WIN_RESERVED = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    private ?string $activeCacheDir = null;

    /** @var array<string,array{0:string,1:?string}> */
    private array $alias = [];

    private ?bool $aliasLoaded = null;

    private string $cacheDir = '';

    private bool $cacheEnabled = false;

    private bool $cachePreloaded = false;

    private bool $cacheReadable = false;

    private bool $cacheWriteEnabled = false;

    private CanonicalMatcherEngine $engine;

    private bool $finalized = false;

    private CanonicalMatcherIndex $index;

    /** @var array<string,MatcherGroup|null> */
    private array $loadedGroups = [];

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

    /** @return array<string,array{0:string,1:?string}> */
    public function aliasIndex(): array
    {
        if (!$this->cacheReadable) {
            return $this->alias;
        }
        if ($this->aliasLoaded === true) {
            return $this->alias;
        }

        $file = $this->cacheStorageDir() . DIRECTORY_SEPARATOR . self::F_ALIASES;
        if (!is_file($file)) {
            throw new \RuntimeException('Sharded route alias cache is missing. Rebuild the route cache.');
        }

        $blob = require $file;
        if (!is_array($blob) || ($blob['_version'] ?? null) !== self::INDEX_CACHE_VERSION) {
            throw new \RuntimeException('Stale sharded route alias cache. Rebuild the route cache.');
        }
        $data = $blob['_data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException('Stale sharded route alias cache. Rebuild the route cache.');
        }
        if ($this->verifyCacheOnLoad) {
            $stored = $blob['_hash'] ?? null;
            if (!is_string($stored) || !hash_equals($stored, hash('xxh128', serialize($data)))) {
                throw new \RuntimeException('Sharded route alias cache hash mismatch.');
            }
        }

        $this->alias = matcher_normalize_alias_pairs($data);
        $this->aliasLoaded = true;

        return $this->alias;
    }

    #[\Override]
    public function canBootFromCache(): bool
    {
        return $this->cacheEnabled
            && !$this->cacheWriteEnabled
            && $this->resolveActiveCacheDir() !== null;
    }

    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheDir = rtrim($cacheLocation, '/\\');
        $this->activeCacheDir = null;
        $this->cacheReadable = false;
        $this->cachePreloaded = false;

        return $this;
    }

    public function enableCacheWrite(bool $enable = true): self
    {
        $this->cacheWriteEnabled = $enable;

        return $this;
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->bootIndex();
        if ($this->cacheEnabled && $this->cacheWriteEnabled) {
            $this->publishGeneration();
        }

        $this->cacheReadable = $this->cacheEnabled && $this->resolveActiveCacheDir() !== null;
        if ($this->cacheReadable && $this->cacheWriteEnabled) {
            $this->index = new CanonicalMatcherIndex();
            $this->alias = [];
            $this->middlewareRequirements = [];
        } elseif ($this->cacheReadable && !$this->index->isEmpty()) {
            $this->preloadProductionGroups();
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
        return $this->matchCanonical($method, $host, $path, true);
    }

    public function matchOutcome(string $method, string $host, string $path): MatchOutcome
    {
        $this->finalize();
        $outcome = $this->matchCanonical($method, $host, $path, false);
        if (!$outcome instanceof MatchOutcome) {
            throw new \LogicException('Non-compact matcher must return a MatchOutcome.');
        }

        return $outcome;
    }

    /** @return list<string> */
    public function middlewareRequirements(): array
    {
        return !$this->cacheReadable
            ? array_keys($this->middlewareRequirements)
            : ShardedCacheGeneration::middlewareRequirements($this->cacheDir, self::INDEX_CACHE_VERSION);
    }

    /** @return array{string,string|null}|null */
    public function resolveAlias(string $name): ?array
    {
        $index = $this->aliasIndex();

        return $index[$name] ?? null;
    }

    /**
     * @param array<string,MatcherGroup> $groups
     * @param array<string,DynamicEntry> $entries
     */
    private function addDynamicBucket(array &$groups, int $count, string $prefix, array $entries): void
    {
        $bucket = $prefix === '*' ? self::SHARD_DYNAMIC : $prefix;
        $groups[$bucket] ??= ['static' => [], 'dynamic' => []];
        foreach ($entries as $path => $entry) {
            $groups[$bucket]['dynamic'][$count][$prefix][$path] = $entry;
        }
    }

    private function bootIndex(): void
    {
        $this->index ??= new CanonicalMatcherIndex();
        $this->engine ??= new CanonicalMatcherEngine();
    }

    private function cacheStorageDir(): string
    {
        return $this->activeCacheDir ?? $this->cacheDir;
    }

    /** @return list<MatcherGroup> */
    private function loadCandidateGroups(string $host, string $bucket): array
    {
        $groups = [];
        $primary = $this->loadGroup($host, $bucket);
        if ($primary !== null) {
            $groups[] = $primary;
        }
        if ($bucket !== self::SHARD_DYNAMIC) {
            $dynamic = $this->loadGroup($host, self::SHARD_DYNAMIC);
            if ($dynamic !== null) {
                $groups[] = $dynamic;
            }
        }

        return $groups;
    }

    /** @return MatcherGroup|null */
    private function loadGroup(string $host, string $bucket): ?array
    {
        $file = sharded_matcher_shard_file_path(
            $this->cacheStorageDir(),
            $host,
            $bucket,
            self::WIN_RESERVED,
        );
        if (array_key_exists($file, $this->loadedGroups)) {
            return $this->loadedGroups[$file];
        }
        if ($this->cachePreloaded) {
            return null;
        }
        if (!is_file($file)) {
            return $this->loadedGroups[$file] = null;
        }

        $blob = require $file;
        if (!is_array($blob) || ($blob['_version'] ?? null) !== self::INDEX_CACHE_VERSION) {
            throw new \RuntimeException("Stale sharded route cache ({$file}). Rebuild the route cache.");
        }
        $data = $blob['_data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException("Stale sharded route cache ({$file}). Rebuild the route cache.");
        }
        if ($this->verifyCacheOnLoad) {
            $stored = $blob['_hash'] ?? null;
            if (!is_string($stored) || !hash_equals($stored, hash('xxh128', serialize($data)))) {
                throw new \RuntimeException("Sharded route cache hash mismatch ({$file}).");
            }
        }

        $validated = new CanonicalMatcherIndex();
        $validated->replaceFromCache([$host => $data]);
        $hosts = $validated->hosts();

        return $this->loadedGroups[$file] = $hosts[$host] ?? null;
    }

    /** @return int|array{0:int,1:array<string,string>}|MatchOutcome */
    private function matchCanonical(string $method, string $host, string $path, bool $compact): int|array|MatchOutcome
    {
        if (!$this->cacheReadable) {
            $hosts = $this->index->hosts();
            $hostGroup = $hosts[$host] ?? null;
            $wildcardGroup = $host !== '*' ? ($hosts['*'] ?? null) : null;

            return $compact
                ? $this->engine->matchSingleCompiled($hostGroup, $wildcardGroup, $method, $path)
                : $this->engine->matchSingle($hostGroup, $wildcardGroup, $method, $path);
        }

        $bucket = CanonicalMatcherIndex::prefixForPath($path);
        $bucket = $bucket === '' ? self::SHARD_ROOT : $bucket;
        $hostGroups = $this->loadCandidateGroups($host, $bucket);
        $wildcardGroups = $host !== '*' ? $this->loadCandidateGroups('*', $bucket) : [];

        return $compact
            ? $this->engine->matchCompiled($hostGroups, $wildcardGroups, $method, $path)
            : $this->engine->match($hostGroups, $wildcardGroups, $method, $path);
    }

    /**
     * @param array<string,MatcherGroup> $groups
     * @param DynamicBuckets $dynamic
     */
    private function partitionDynamicIndex(array &$groups, array $dynamic): void
    {
        foreach ($dynamic as $count => $prefixes) {
            foreach ($prefixes as $prefix => $entries) {
                $this->addDynamicBucket($groups, $count, $prefix, $entries);
            }
        }
    }

    /**
     * @param MatcherGroup $index
     * @return array<string,MatcherGroup>
     */
    private function partitionHostIndex(array $index): array
    {
        $groups = [];
        foreach ($index['static'] as $path => $verbs) {
            $bucket = CanonicalMatcherIndex::prefixForPath($path);
            $bucket = $bucket === '' ? self::SHARD_ROOT : $bucket;
            $groups[$bucket] ??= ['static' => [], 'dynamic' => []];
            $groups[$bucket]['static'][$path] = $verbs;
        }

        $this->partitionDynamicIndex($groups, $index['dynamic']);

        return $groups;
    }

    /** @return array<string,array<string,MatcherGroup>> */
    private function partitionIndex(): array
    {
        $groups = [];
        foreach ($this->index->hosts() as $host => $index) {
            $groups[$host] = $this->partitionHostIndex($index);
        }

        return $groups;
    }

    private function preloadProductionGroups(): void
    {
        $groups = $this->partitionIndex();
        $groups['*'][self::SHARD_ROOT] ??= ['static' => [], 'dynamic' => []];
        foreach ($groups as $host => $buckets) {
            foreach ($buckets as $bucket => $_group) {
                if ($this->loadGroup($host, $bucket) === null) {
                    throw new \RuntimeException(
                        "Sharded route cache is missing expected group '{$host}:{$bucket}'. Rebuild the route cache.",
                    );
                }
            }
        }

        $this->aliasIndex();
        $this->cachePreloaded = true;
    }

    private function publishGeneration(): void
    {
        [$generation, $this->activeCacheDir] = ShardedCacheGeneration::create($this->cacheDir);
        $groups = $this->partitionIndex();
        foreach ($groups as $host => $buckets) {
            foreach ($buckets as $bucket => $group) {
                $this->writeGroup($host, $bucket, $group);
            }
        }

        $groups['*'][self::SHARD_ROOT] ??= ['static' => [], 'dynamic' => []];
        $this->writeGroup('*', self::SHARD_ROOT, $groups['*'][self::SHARD_ROOT]);
        $this->writePayload(
            $this->cacheStorageDir() . DIRECTORY_SEPARATOR . self::F_ALIASES,
            $this->alias,
        );
        ShardedCacheGeneration::publish(
            $this->cacheDir,
            self::INDEX_CACHE_VERSION,
            $generation,
            array_keys($this->middlewareRequirements),
        );
    }

    private function resolveActiveCacheDir(): ?string
    {
        if ($this->activeCacheDir !== null) {
            return $this->activeCacheDir;
        }
        if ($this->cacheDir === '') {
            return null;
        }

        return $this->activeCacheDir = ShardedCacheGeneration::resolve(
            $this->cacheDir,
            self::INDEX_CACHE_VERSION,
        );
    }

    /** @param MatcherGroup $group */
    private function writeGroup(string $host, string $bucket, array $group): void
    {
        $file = sharded_matcher_shard_file_path(
            $this->cacheStorageDir(),
            $host,
            $bucket,
            self::WIN_RESERVED,
        );
        $this->writePayload($file, $group);
    }

    /** @param array<array-key,mixed> $payload */
    private function writePayload(string $file, array $payload): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Failed to create cache dir {$directory}");
        }

        $data = MatcherCachePayloadNormalizer::normalize($payload);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Normalized sharded matcher cache must be an array.');
        }
        $hash = hash('xxh128', serialize($data));
        $php = "<?php\nreturn [\n"
            . "    '_version' => " . self::INDEX_CACHE_VERSION . ",\n"
            . "    '_hash' => " . var_export($hash, true) . ",\n"
            . "    '_data' => " . $this->exportArray($data) . ",\n"
            . "];\n";

        matcher_write_validated_atomic_php_file(
            $file,
            $php,
            static function (array $blob) use ($hash): void {
                $data = $blob['_data'] ?? null;
                if (($blob['_version'] ?? null) !== self::INDEX_CACHE_VERSION || !is_array($data)) {
                    throw new \UnexpectedValueException('Generated sharded matcher payload is invalid.');
                }
                if (($blob['_hash'] ?? null) !== $hash || hash('xxh128', serialize($data)) !== $hash) {
                    throw new \UnexpectedValueException('Generated sharded matcher payload hash mismatch.');
                }
            },
        );

        if ($this->shouldWarmOpcache()) {
            opcache_compile_file($file);
        }
    }
}
