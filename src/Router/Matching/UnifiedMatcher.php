<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

#[\AllowDynamicProperties(false)]
final class UnifiedMatcher implements MatcherInterface
{
    /** @var array<string,?array> resolved groups for no-cache mode */
    private array $memGroups = [];

    /*──────────────────────── factory ────────────────────────*/
    public static function make(): self
    {
        return new self();
    }

    private function __construct()
    {
    }

    /*──────────────────────── public config ───────────────────*/
    public function enableCache(string $dir): self
    {
        $this->cacheEnabled = true;
        $this->cacheDir = rtrim($dir, '/\\');
        return $this;
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        if ($this->cacheEnabled) {
            $this->dumpCacheFiles();
            // free build-time structures
            $this->prefixMap = [];
        }
        $this->finalized = true;
    }

    /*──────────────────────── route registration ─────────────*/
    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $host = $this->normHost($route->getDomain());
        $method = \strtoupper($route->getMethod());
        $prefix = $this->extractPrefix($route);

        // duplicate guard (host+method+path)
        if (isset($this->pathGuard[$host][$method][$route->getPath()])) {
            throw new \LogicException("Duplicate route {$method} {$host}{$route->getPath()}");
        }
        $this->pathGuard[$host][$method][$route->getPath()] = true;

        // store
        $this->prefixMap[$prefix][$method][] = $route;
    }

    /*──────────────────────── runtime match ──────────────────*/
    public function match(string $method, string $host, string $path): array
    {
        $method = \strtoupper($method);
        $host = \strtolower($host ?: '*');
        $path = $path === '' ? '/' : $path;

        foreach ($this->fileKeysForPath($path) as $fileKey) {
            $group = $this->loadGroup($fileKey);
            if ($group === null) {
                continue;
            }

            $prefix = $this->longestPrefixKey($group, $path);
            if ($prefix === null) {
                continue;
            }

            $methodMap = $group[$prefix] ?? null;
            if ($methodMap === null) {
                continue;
            }

            $allowed = \array_keys($methodMap);
            $routes = $methodMap[$method] ?? null;
            if ($routes === null) {
                $this->throw405or404($method, $path, $allowed);
            }

            foreach ($routes as $r) {
                if (!$this->hostMatches($r, $host)) {
                    continue;
                }

                if (!$r->isDynamic()) {
                    if ($r->getPath() === $path) {
                        return [$r, []];
                    }
                    continue;
                }

                if (\preg_match($r->getRegex(), $path, $m) !== 1) {
                    continue;
                }

                $params = [];
                foreach ($r->getVariables() as $i => $name) {
                    $params[$name] = $m[$i + 1];
                }
                return [$r, $params];
            }

            $this->throw405or404($method, $path, $allowed);
        }

        throw new RouteNotFoundException($method, $path);
    }

    /*──────────────────────── data members ───────────────────*/
    /** @var array<string,array<string,list<CompiledRoute>>>   prefix → method → routes */
    private array $prefixMap = [];   // build-time only

    /** @var array<string,true>  memoised file includes */
    private array $loadedFiles = [];

    private bool $cacheEnabled = false;
    private string $cacheDir = '';
    private bool $finalized = false;

    /** duplicate guard: host → method → path */
    private array $pathGuard = [];

    /*──────────────────────── helpers (build-time) ───────────*/
    private function extractPrefix(CompiledRoute $r): string
    {
        $parts = [];
        foreach ($r->getSegments() as $s) {
            if ($s['type'] !== 'lit') {
                break;
            }
            $parts[] = $s['val'];
        }
        return '/' . \implode('/', $parts);
    }

    private function normHost(?string $h): string
    {
        if ($h === null || $h === '') {
            return '*';
        }
        if (\preg_match('/[\x00-\x20]/', $h)) {
            throw new \InvalidArgumentException("Illegal host name: {$h}");
        }
        return \strtolower(rtrim($h, '.'));
    }

    /*──────────────────────── cache dump ----------------------*/
    private function dumpCacheFiles(): void
    {
        foreach ($this->prefixMap as $prefix => $byMethod) {
            $file = $this->filePathForPrefix($prefix);
            if (!\is_dir($d = \dirname($file)) && !@mkdir($d, 0775, true) && !\is_dir($d)) {
                throw new \RuntimeException("Failed to create cache dir {$d}");
            }

            $payload = [$prefix => $byMethod];
            if (\is_file($file)) {
                /** @var array $old */
                $old = @require $file;
                $payload = \array_replace_recursive($old, $payload);
            }

            $blob = ValueSerializer::serialize($payload);
            $code = "<?php\nreturn \\Infocyph\\InterMix\\Serializer\\ValueSerializer"
                . "::unserialize(" . \var_export($blob, true) . ");\n";

            $tmp = $file . '.' . \uniqid('', true) . '.tmp';
            \file_put_contents($tmp, $code, LOCK_EX);
            @chmod($tmp, 0664);
            @rename($tmp, $file);
        }
    }

    /*──────────────────────── helpers (runtime) ---------------*/
    private function loadGroup(string $fileKey): ?array
    {
        /*──── ① cached file mode ─────────────────────────────────────────*/
        if ($this->cacheEnabled) {
            $file = "{$this->cacheDir}/{$fileKey}.php";
            if (!isset($this->loadedFiles[$file])) {
                $this->loadedFiles[$file] = \is_file($file) ? require $file : null;
            }
            return $this->loadedFiles[$file];
        }

        /*──── ② in-memory (dev) mode ─────────────────────────────────────*/
        if (array_key_exists($fileKey, $this->memGroups)) {
            return $this->memGroups[$fileKey];
        }

        // build bucket once
        $bucket = null;
        $needle = $fileKey === '__root' ? '' : $fileKey;

        foreach ($this->prefixMap as $prefix => $methods) {
            $firstSeg = $prefix === '/' ? '__root'
                : \explode('/', \ltrim($prefix, '/'), 2)[0];

            if ($firstSeg !== $fileKey) {
                continue;
            }

            // prefix → method map (same shape the cached file would have)
            $bucket[$prefix] = $methods;
        }

        return $this->memGroups[$fileKey] = $bucket;
    }


    /** first-segment key(s) for a path */
    private function fileKeysForPath(string $path): array
    {
        if ($path === '/' || $path === '') {
            return ['__root'];
        }
        $first = \explode('/', ltrim($path, '/'), 2)[0];
        return [$first, '__root'];
    }

    /** cache file location */
    private function filePathForPrefix(string $prefix): string
    {
        return $prefix === '/'
            ? "{$this->cacheDir}/__root.php"
            : "{$this->cacheDir}/" . \explode('/', ltrim($prefix, '/'), 2)[0] . '.php';
    }

    /** pick longest key in $group that prefixes $path */
    private function longestPrefixKey(array $group, string $path): ?string
    {
        $best = null;
        foreach ($group as $p => $_) {
            $match = $p === '/' || \strncmp($path, $p, \strlen($p)) === 0
                && ($path === $p || $path[\strlen($p)] === '/');

            if ($match && ($best === null || \strlen($p) > \strlen($best))) {
                $best = $p;
            }
        }
        return $best;
    }

    private function hostMatches(CompiledRoute $r, string $host): bool
    {
        $need = $r->getDomain();
        return $need === null || $need === '' || \strcasecmp($need, $host) === 0 || $need === '*';
    }

    private function throw405or404(string $m, string $p, array $allowed): never
    {
        if ($allowed !== []) {
            throw new MethodNotAllowedException($m, $p, $allowed);
        }
        throw new RouteNotFoundException($m, $p);
    }
}
