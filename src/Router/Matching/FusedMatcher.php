<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * FusedMatcher
 *
 * Concrete matcher implementation that stores a combined in-memory route table
 * and supports optional single-file cache persistence. Routes are organised
 * per-host into two buckets:
 *  - "static" : exact path -> verb -> CompiledRoute map (fast lookup)
 *  - "trie"   : dynamic route trie for parameterised segments
 *
 * Responsibilities:
 *  - Accept compiled routes and insert them into the appropriate bucket.
 *  - Provide match(method, host, path) to return a matched CompiledRoute and
 *    extracted parameters or throw the appropriate routing exception.
 *  - Optionally emit a single-file PHP cache blob and lazily reload it on
 *    first match when cache is enabled.
 *
 * Notes:
 *  - When cache is enabled this class will write a single PHP file in
 *    finalize() and subsequently free in-memory tables to reduce resident
 *    memory; the file is lazily loaded on first match or alias access.
 *  - The matcher enforces that no routes are added after finalize() is called.
 *
 * @package Infocyph\Webrick\Router\Matching
 */
final class FusedMatcher extends AbstractMatcher implements MatcherInterface
{
    /**
     * Host-bucket data structure.
     *
     * Shape:
     *   [
     *     'host.name' => [
     *         'static' => array<string, array<string, CompiledRoute>>, // path => [VERB => CompiledRoute]
     *         'trie'   => array // trie root node created by newNode()
     *     ],
     *     '*' => ... // wildcard host
     *   ]
     *
     * @var array<string, array{static: array, trie: array}>
     */
    private array $hosts = [];

    /**
     * Alias index mapping route name => [path, domain|null].
     *
     * @var array<string, array{0:string,1:?string}>
     */
    private array $alias = [];

    /**
     * Whether single-file caching has been enabled.
     *
     * @var bool
     */
    private bool $cacheEnabled = false;

    /**
     * Path to the single-file cache when caching is enabled.
     *
     * @var string
     */
    private string $cacheFile = '';

    /**
     * Whether the cache file has been loaded into memory (lazy load).
     *
     * @var bool
     */
    private bool $cacheLoaded = false;

    /**
     * Whether the matcher has been finalized (no further route additions allowed).
     *
     * @var bool
     */
    private bool $finalized = false;

    /*──────────── factory/config ────────────*/

    /**
     * Create a new FusedMatcher instance.
     *
     * @return self
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Private constructor to enforce factory creation.
     */
    private function __construct()
    {
    }

    /**
     * Enable single-file cache output and set the target file path.
     *
     * The matcher will attempt to write the cache in finalize() if routes have
     * been added and the target file is absent.
     *
     * @param string $cacheLocation Path to the output cache file
     * @return self Fluent self for chaining
     */
    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheFile = $cacheLocation;
        return $this;
    }

    /**
     * Indicate whether a ready cache file exists such that the matcher can be
     * booted from cache without compiling routes.
     *
     * @return bool True when cache is enabled and the cache file exists
     */
    public function canBootFromCache(): bool
    {
        return $this->cacheEnabled && \is_file($this->cacheFile);
    }

    /**
     * Finalize the matcher.
     *
     * Behavior:
     *  - If caching is enabled and the single-file cache does not exist but the
     *    in-memory hosts table is populated, the cache file will be written.
     *  - When the cache is written the in-memory tables are cleared to allow
     *    lazy reload from the cache file later.
     *
     * This method is idempotent.
     *
     * @return void
     */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        // Write cache only if table is built and file is absent
        if ($this->cacheEnabled && !\is_file($this->cacheFile) && $this->hosts !== []) {
            $this->dumpCache();
            // Free memory; tables will be lazy-loaded on first match()
            $this->hosts = [];
            $this->alias = [];
            $this->cacheLoaded = false;
        }
        $this->finalized = true;
    }

    /*──────────── registration ────────────*/

    /**
     * Add a CompiledRoute to the matcher.
     *
     * The route is canonicalised by domain and inserted into the host-specific
     * static map or dynamic trie depending on whether it is dynamic.
     *
     * @param CompiledRoute $route Compiled route instance to add
     * @throws \LogicException When attempting to add routes after finalize()
     * @return void
     */
    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $host = $this->canonicalRouteHost($route->getDomain());
        $verb = \strtoupper($route->getMethod());

        // Ensure host bucket exists with both static and trie slots.
        $this->hosts[$host] ??= [self::K_STATIC => [], self::K_TRIE => $this->newNode()];

        if ($route->isDynamic()) {
            $this->insertDynamic($host, $verb, $route);
        } else {
            $this->insertStatic($host, $verb, $route);
        }

        // Record alias (name → [path, domain]) when the route has a name.
        if (($name = $route->getName()) !== null && $name !== '') {
            $this->alias[$name] = [$route->getPath(), $route->getDomain()];
        }
    }

    /**
     * Insert a static (exact path) route into the host static table.
     *
     * @param string $host Canonical host key
     * @param string $verb HTTP verb (uppercased)
     * @param CompiledRoute $r Compiled route being inserted
     * @throws \LogicException On duplicate insertion of the same verb/path
     * @return void
     */
    private function insertStatic(string $host, string $verb, CompiledRoute $r): void
    {
        $path = $r->getPath();
        $table = &$this->hosts[$host][self::K_STATIC];

        if (isset($table[$path][$verb])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }
        $table[$path][$verb] = $r;
    }

    /**
     * Insert a dynamic route into the host trie.
     *
     * @param string $host Canonical host key
     * @param string $verb HTTP verb (uppercased)
     * @param CompiledRoute $r Compiled dynamic route
     * @return void
     */
    private function insertDynamic(string $host, string $verb, CompiledRoute $r): void
    {
        $node = &$this->hosts[$host][self::K_TRIE];
        $this->trieInsert($node, $r, $verb);
    }

    /*──────────── runtime match ────────────*/

    /**
     * Match an incoming request method/host/path to a compiled route.
     *
     * Lazy cache behaviour:
     *  - If caching is enabled and cache wasn't loaded, attempt to require the
     *    cache file and hydrate internal tables. If the file is missing a
     *    RouteNotFoundException is thrown early.
     *
     * Matching order:
     *  1. Static table for host then wildcard '*'
     *  2. Dynamic trie for host then wildcard '*'
     *  3. If candidate verbs exist but none match, throw MethodNotAllowedException
     *  4. Otherwise throw RouteNotFoundException
     *
     * @param string $method HTTP method (any case)
     * @param string $host Host header value (expected ASCII/lowercase)
     * @param string $path Request path
     * @throws MethodNotAllowedException When resource exists but verb not allowed
     * @throws RouteNotFoundException When no route matches the path/host
     * @return array{0:CompiledRoute,1:array<string,string>} Tuple [route, params]
     */
    public function match(string $method, string $host, string $path): array
    {
        /* Lazy-load single-file cache if enabled and not yet loaded. */
        if ($this->cacheEnabled && !$this->cacheLoaded) {
            if (!\is_file($this->cacheFile)) {
                // No cache present — cannot resolve routes in this mode.
                throw new RouteNotFoundException($method, $path);
            }

            /** @var array{_hash:string,_data:array,_alias?:array<string,array{0:string,1:?string}>} $blob */
            $blob = require $this->cacheFile;

            if ($this->verifyCacheOnLoad) {
                if (!isset($blob[self::H_HASH], $blob[self::H_DATA])) {
                    throw new \RuntimeException('Route cache missing Hash.');
                }
                $calc = \hash('xxh3', \json_encode($blob[self::H_DATA], \JSON_THROW_ON_ERROR));
                if (!\hash_equals($blob[self::H_HASH], $calc)) {
                    throw new \RuntimeException('Route cache Hash mismatch.');
                }
            }

            // Hydrate internal structures from the blob.
            $this->hosts = $blob[self::H_DATA] ?? [];
            $this->alias = $blob[self::H_ALIAS] ?? [];
            $this->cacheLoaded = true;

            // Warm opcache for the cache file if available.
            if (\function_exists('opcache_compile_file')) {
                @\opcache_compile_file($this->cacheFile);
            }
        }

        $verb = \strtoupper($method);
        $host = \strtolower($host);

        /** @var array<string,bool> $allowedSet Accumulator for allowed verbs when match fails */
        $allowedSet = [];

        // 1) Try static table (host then wildcard)
        if ($hit = $this->matchStatic($host, $verb, $path, $allowedSet)) {
            return $hit;
        }

        // 2) Try dynamic trie descent (host then wildcard)
        if ($hit = $this->matchTrie($host, $verb, $path, $allowedSet)) {
            return $hit;
        }

        // 3) No route found — determine appropriate exception
        if ($allowedSet !== []) {
            throw new MethodNotAllowedException($verb, $path, \array_keys($allowedSet));
        }
        throw new RouteNotFoundException($verb, $path);
    }

    /**
     * Attempt to match against the static tables for a host and wildcard.
     *
     * On success returns [$route, []] (no params). When a path is present but
     * no verb matches the allowedSet is populated.
     *
     * @param string $host Canonical host key
     * @param string $verb Uppercased HTTP verb
     * @param string $path Request path
     * @param array<string,bool> $allowedSet Accumulator for allowed verbs (by-ref)
     * @return array{0:CompiledRoute,1:array<string,string>}|null Match tuple or null
     */
    private function matchStatic(string $host, string $verb, string $path, array &$allowedSet): ?array
    {
        foreach ([$host, '*'] as $h) {
            $map = $this->hosts[$h][self::K_STATIC][$path] ?? null;
            if ($map === null) {
                continue;
            }

            if ($r = $this->pickVerbRoute($map, $verb)) {
                return [$r, []];
            }

            $this->addAllowedFromMap($map, $allowedSet);
        }
        return null;
    }

    /**
     * Attempt to match against the dynamic trie for a host and wildcard.
     *
     * @param string $host Canonical host key
     * @param string $verb Uppercased HTTP verb
     * @param string $path Request path
     * @param array<string,bool> $allowedSet Accumulator for allowed verbs (by-ref)
     * @return array{0:CompiledRoute,1:array<string,string>}|null Match tuple or null
     */
    private function matchTrie(string $host, string $verb, string $path, array &$allowedSet): ?array
    {
        $segments = $this->explodePath($path);
        foreach ([$host, '*'] as $h) {
            $root = $this->hosts[$h][self::K_TRIE] ?? null;
            if (!$root) {
                continue;
            }

            $hit = null;
            $params = [];
            if ($this->trieWalkNode($root, $segments, 0, $verb, $params, $allowedSet, $hit)) {
                return $hit; // [$route, $params]
            }
        }
        return null;
    }

    /*──────────── alias accessors ────────────*/

    /**
     * Return the alias index mapping name => [path, domain].
     *
     * If caching is enabled and not yet loaded, this will lazily load the alias
     * side-data from the cache file.
     *
     * @return array<string, array{0:string,1:?string}>
     */
    public function aliasIndex(): array
    {
        // Ensure the lazy cache load occurred if caching is enabled
        if ($this->cacheEnabled && !$this->cacheLoaded && \is_file($this->cacheFile)) {
            /** @var array{_data:array,_alias?:array<string,array{0:string,1:?string}>} $blob */
            $blob = require $this->cacheFile;
            $this->alias = $blob[self::H_ALIAS] ?? $this->alias;
            $this->hosts = $this->hosts ?: ($blob[self::H_DATA] ?? []);
            $this->cacheLoaded = true;
        }
        return $this->alias;
    }

    /**
     * Resolve a named route to its [path, domain] tuple.
     *
     * @param string $name Route name
     * @return array{0:string,1:?string}|null [path, domain] or null when not found
     */
    public function resolveAlias(string $name): ?array
    {
        $idx = $this->aliasIndex();
        return $idx[$name] ?? null;
    }

    /*──────────── cache export (single file) ────────────*/

    /**
     * Dump the in-memory host and alias tables into the configured cache file.
     *
     * The cache blob contains a checksum (xxh3) and a timestamp to allow basic
     * integrity checks and identifying stale files.
     *
     * @throws \RuntimeException When the cache directory cannot be created
     * @return void
     */
    private function dumpCache(): void
    {
        $dir = \dirname($this->cacheFile);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir {$dir}");
        }

        $payloadHosts = $this->hosts;
        $crc = \hash('xxh3', \json_encode($payloadHosts, \JSON_THROW_ON_ERROR));

        $php = "<?php\nreturn [\n"
            . "    '" . self::H_HASH . "'  => " . \var_export($crc, true) . ",\n"
            . "    '" . self::H_TS . "'  => " . \var_export(date(DATE_ATOM), true) . ",\n"
            . "    '" . self::H_DATA . "' => " . $this->exportArray($payloadHosts) . ",\n"
            . "    '" . self::H_ALIAS . "' => " . $this->exportArray($this->alias) . ",\n"
            . "];\n";

        $tmp = $this->cacheFile . '.' . \uniqid('', true) . '.tmp';
        \file_put_contents($tmp, $php, \LOCK_EX);
        @\chmod($tmp, 0664);
        @\rename($tmp, $this->cacheFile);

        if (\function_exists('opcache_compile_file')) {
            @\opcache_compile_file($this->cacheFile);
        }
    }
}
