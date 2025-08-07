<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Closure;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

#[\AllowDynamicProperties(false)]
final class UnifiedMatcher implements MatcherInterface
{
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

        if ($this->cacheEnabled && !file_exists($this->cacheDir . DIRECTORY_SEPARATOR . '__root.php')) {
            $this->dumpCacheFiles();
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

        // duplicate guard
        if (isset($this->pathGuard[$host][$method][$route->getPath()])) {
            throw new \LogicException("Duplicate route {$method} {$host}{$route->getPath()}");
        }
        $this->pathGuard[$host][$method][$route->getPath()] = true;

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
    /** @var array<string,array<string,list<CompiledRoute>>> */
    private array $prefixMap = [];   // build-time only
    private array $loadedFiles = [];   // file include memo
    private bool $cacheEnabled = false;
    private string $cacheDir = '';
    private bool $finalized = false;
    private array $pathGuard = [];  // duplicate guard

    /** @var array<string,?array> resolved groups for dev mode */
    private array $memGroups = [];

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

            if (!\is_dir($dir = \dirname($file)) &&
                !@mkdir($dir, 0775, true) && !\is_dir($dir)) {
                throw new \RuntimeException("Failed to create cache dir {$dir}");
            }

            // merge with existing file contents (if any)
            $payload = [$prefix => $byMethod];
            if (\is_file($file)) {
                /** @var array $old */
                $old = @require $file;
                $payload += $old;               // keep older prefixes too
            }

            $php = "<?php\nreturn " . $this->exportArray($payload) . ";\n";
            $tmp = $file . '.' . \uniqid('', true) . '.tmp';
            \file_put_contents($tmp, $php, LOCK_EX);
            @chmod($tmp, 0664);
            @rename($tmp, $file);
        }
    }

    /* export helpers ----------------------------------------------------*/
    private function exportArray(array $a, int $depth = 0): string
    {
        $indent = str_repeat('    ', $depth);
        $out = "[\n";
        foreach ($a as $k => $v) {
            $out .= $indent . '    ' . \var_export($k, true) . ' => ';
            $out .= \is_array($v)
                ? $this->exportArray($v, $depth + 1)
                : $this->exportValue($v, $depth + 1);
            $out .= ",\n";
        }
        return $indent . rtrim($out, ",\n") . "\n" . $indent . "]";
    }

    private function exportValue(mixed $v, int $depth): string
    {
        return $v instanceof CompiledRoute
            ? $this->exportRoute($v)
            : (\is_array($v) ? $this->exportArray($v, $depth) : \var_export($v, true));
    }


    private function exportRoute(CompiledRoute $r): string
    {
        /* 1. Fast path – handler has NO Closure */
        if (!$this->handlerHasClosure($r->getHandler())) {
            return 'new \\' . CompiledRoute::class . '('
                // method, path, handler
                . \var_export($r->getMethod(), true) . ', '
                . \var_export($r->getPath(), true) . ', '
                . \var_export($r->getHandler(), true) . ', '
                // domain, middleware, name
                . \var_export($r->getDomain(), true) . ', '
                . \var_export($r->getMiddlewares(), true) . ', '
                . \var_export($r->getName(), true) . ', '
                // dynamic, regex, variables
                . ($r->isDynamic() ? 'true' : 'false') . ', '
                . \var_export($r->getRegex(), true) . ', '
                . \var_export($r->getVariables(), true) . ', '
                // index, cors, segments
                . \var_export($r->getIndex(), true) . ', '
                . \var_export($r->getCorsPolicy(), true) . ', '
                . \var_export($r->getSegments(), true)
                . ')';
        }

        /* 2. Slow path – handler *has* Closure -> ValueSerializer */
        return '\\' . ValueSerializer::class
            . '::unserialize(' . \var_export(ValueSerializer::serialize($r), true) . ')';
    }


    private function handlerHasClosure(callable|array|string $h): bool
    {
        if ($h instanceof Closure) {
            return true;
        }
        if (\is_array($h)) {
            return ($h[0] ?? null) instanceof Closure
                || ($h[1] ?? null) instanceof Closure;
        }
        return false;
    }

    /*──────────────────────── helpers (runtime) ---------------*/
    private function loadGroup(string $fileKey): ?array
    {
        /* 1) cached-file mode ------------------------------------------ */
        if ($this->cacheEnabled) {
            $file = "{$this->cacheDir}/{$fileKey}.php";
            if (!isset($this->loadedFiles[$file])) {
                $this->loadedFiles[$file] = \is_file($file) ? require $file : null;
            }
            return $this->loadedFiles[$file];
        }

        /* 2) dev (in-memory) mode -------------------------------------- */
        if (\array_key_exists($fileKey, $this->memGroups)) {
            return $this->memGroups[$fileKey];
        }

        $bucket = null;
        foreach ($this->prefixMap as $prefix => $methods) {
            $firstSeg = $prefix === '/' ? '__root'
                : \explode('/', \ltrim($prefix, '/'), 2)[0];
            if ($firstSeg === $fileKey) {
                $bucket[$prefix] = $methods;
            }
        }
        return $this->memGroups[$fileKey] = $bucket;
    }

    /* path → candidate file keys */
    private function fileKeysForPath(string $path): array
    {
        if ($path === '/' || $path === '') {
            return ['__root'];
        }
        $first = \explode('/', \ltrim($path, '/'), 2)[0];
        return [$first, '__root'];
    }

    /* prefix → cache file location */
    private function filePathForPrefix(string $prefix): string
    {
        return $prefix === '/'
            ? "{$this->cacheDir}/__root.php"
            : "{$this->cacheDir}/" . \explode('/', \ltrim($prefix, '/'), 2)[0] . '.php';
    }

    /* choose longest prefix key that matches $path */
    private function longestPrefixKey(array $group, string $path): ?string
    {
        $best = null;
        foreach ($group as $p => $_) {
            $ok = $p === '/'
                || (\strncmp($path, $p, \strlen($p)) === 0 &&
                    ($path === $p || $path[\strlen($p)] === '/'));

            if ($ok && (\strlen($p) > \strlen($best ?? ''))) {
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
