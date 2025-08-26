<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

final class FusedMatcher extends AbstractMatcher implements MatcherInterface
{
    /*──────────── state ────────────*/
    /** host-bucket data: [$host]['static'|'trie'] */
    private array $hosts = [];

    private bool $cacheEnabled = false;
    private string $cacheFile = '';
    private bool $cacheLoaded = false;
    private bool $finalized = false;

    /*──────────── factory/config ────────────*/
    public static function make(): self
    {
        return new self();
    }

    private function __construct()
    {
    }

    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheFile = $cacheLocation;
        return $this;
    }

    /** true when single-file cache already exists and we can skip compile */
    public function canBootFromCache(): bool
    {
        return $this->cacheEnabled && \is_file($this->cacheFile);
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        // Write cache only if table is built and file is absent
        if ($this->cacheEnabled && !\is_file($this->cacheFile) && $this->hosts !== []) {
            $this->dumpCache();
            $this->hosts = [];      // free memory; will lazy-load
            $this->cacheLoaded = false;
        }
        $this->finalized = true;
    }

    /*──────────── registration ────────────*/
    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $host = $this->canonicalRouteHost($route->getDomain());
        $verb = \strtoupper($route->getMethod());

        $this->hosts[$host] ??= [self::K_STATIC => [], self::K_TRIE => $this->newNode()];

        if ($route->isDynamic()) {
            $this->insertDynamic($host, $verb, $route);
        } else {
            $this->insertStatic($host, $verb, $route);
        }
    }

    private function insertStatic(string $host, string $verb, CompiledRoute $r): void
    {
        $path = $r->getPath();
        $table = &$this->hosts[$host][self::K_STATIC];

        if (isset($table[$path][$verb])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }
        $table[$path][$verb] = $r;
    }

    private function insertDynamic(string $host, string $verb, CompiledRoute $r): void
    {
        $node = &$this->hosts[$host][self::K_TRIE];
        $this->trieInsert($node, $r, $verb);
    }

    /*──────────── runtime match ────────────*/
    public function match(string $method, string $host, string $path): array
    {
        /* lazy-load single cache file */
        if ($this->cacheEnabled && !$this->cacheLoaded) {
            if (!\is_file($this->cacheFile)) {
                throw new RouteNotFoundException($method, $path);
            }

            /** @var array{_hash:string,_data:array} $blob */
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

            $this->hosts = $blob[self::H_DATA] ?? [];
            $this->cacheLoaded = true;

            if (\function_exists('opcache_compile_file')) {
                @\opcache_compile_file($this->cacheFile);
            }
        }

        $verb = \strtoupper($method);
        $host = \strtolower($host);

        /** @var array<string,bool> $allowedSet */
        $allowedSet = [];

        // ① static table (host then wildcard)
        if ($hit = $this->matchStatic($host, $verb, $path, $allowedSet)) {
            return $hit;
        }

        // ② trie descent (host then wildcard)
        if ($hit = $this->matchTrie($host, $verb, $path, $allowedSet)) {
            return $hit;
        }

        // ③ verdict
        if ($allowedSet !== []) {
            throw new MethodNotAllowedException($verb, $path, \array_keys($allowedSet));
        }
        throw new RouteNotFoundException($verb, $path);
    }

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
                return $hit; // [$route,$params]
            }
        }
        return null;
    }

    /*──────────── cache export (single file) ────────────*/
    private function dumpCache(): void
    {
        $dir = \dirname($this->cacheFile);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir {$dir}");
        }

        $payload = $this->hosts;
        $crc = \hash('xxh3', \json_encode($payload, \JSON_THROW_ON_ERROR));

        $php = "<?php\nreturn [\n"
            . "    '" . self::H_HASH . "'  => " . \var_export($crc, true) . ",\n"
            . "    '" . self::H_TS . "'  => " . \var_export(date(DATE_ATOM), true) . ",\n"
            . "    '" . self::H_DATA . "' => " . $this->exportArray($payload) . ",\n"
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
