<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * GeneratedMatcher
 *
 * Matcher backend that generates PHP code for route matching and compiles it
 * into a closure. The generated code uses bucketed switch blocks for static
 * and dynamic path checks per host to minimize runtime structure walking.
 *
 * Cache mode loads generated matcher source from a PHP file when present.
 * Cache file generation is explicitly enabled only by route-cache tooling.
 * In-memory mode builds the closure directly during finalize().
 */
final class GeneratedMatcher extends AbstractMatcher implements MatcherInterface
{
    /**
     * Route alias index: name => [path, domain|null].
     *
     * @var array<string,array{0:string,1:?string}>
     */
    private array $alias = [];

    /**
     * Whether generated cache file mode is enabled.
     */
    private bool $cacheEnabled = false;

    /**
     * Path to generated cache file.
     */
    private string $cacheFile = '';

    /**
     * Whether cache file has been loaded and compiled into closure.
     */
    private bool $cacheLoaded = false;

    /**
     * Whether cache file writing is explicitly enabled (tooling-only path).
     */
    private bool $cacheWriteEnabled = false;

    /**
     * Compiled generated matcher closure.
     *
     * Signature:
     *   function(string $verb, string $host, string $path): array{
     *     hit:?CompiledRoute, params:array<string,string>, allowed:array<string,bool>
     *   }
     */
    private ?Closure $compiledFn = null;

    /**
     * Whether matcher has been finalized.
     */
    private bool $finalized = false;

    /**
     * Duplicate guard: host => method => path => true.
     *
     * @var array<string,array<string,array<string,bool>>>
     */
    private array $guard = [];

    /**
     * Collected routes by host key.
     *
     * @var array<string,list<CompiledRoute>>
     */
    private array $hostRoutes = [];

    private function __construct() {}

    public static function make(): self
    {
        return new self();
    }

    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $host = $this->canonicalRouteHost($route->getDomain());
        $verb = HttpMethodEnum::normalize($route->getMethod());
        $path = $route->getPath();

        if (isset($this->guard[$host][$verb][$path])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }
        $this->guard[$host][$verb][$path] = true;
        $this->hostRoutes[$host][] = $route;

        if (($name = $route->getName()) !== null && $name !== '') {
            $this->alias[$name] = [$route->getPath(), $route->getDomain()];
        }
    }

    public function aliasIndex(): array
    {
        if ($this->cacheEnabled) {
            if (!$this->cacheLoaded && \is_file($this->cacheFile)) {
                $this->loadCacheBlob();
            }
        }

        return $this->alias;
    }

    #[\Override]
    public function canBootFromCache(): bool
    {
        return $this->cacheEnabled && \is_file($this->cacheFile);
    }

    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheFile = $cacheLocation;

        return $this;
    }

    /**
     * Explicitly allow cache-file writes from finalize().
     *
     * This is intentionally opt-in and should only be used by route-cache tooling.
     */
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

        if ($this->cacheEnabled) {
            $cacheFileExists = \is_file($this->cacheFile);
            if (!$cacheFileExists && $this->cacheWriteEnabled && $this->hostRoutes !== []) {
                $this->dumpCache();
                $cacheFileExists = true;
            }

            if ($cacheFileExists) {
                // Cache boot mode: load lazily from file.
                $this->hostRoutes = [];
                $this->guard = [];
                $this->compiledFn = null;
                $this->cacheLoaded = false;
            } elseif ($this->compiledFn === null) {
                // No cache file available: keep runtime path purely in-memory.
                $this->compiledFn = $this->compileClosureFromCode($this->buildMatcherCode());
            }
        } elseif ($this->compiledFn === null) {
            $this->compiledFn = $this->compileClosureFromCode($this->buildMatcherCode());
        }

        $this->finalized = true;
    }

    public function match(string $method, string $host, string $path): array
    {
        [$verb, $host, $path] = $this->normalizeMatchInput($method, $host, $path);
        $compiledFn = $this->ensureCompiledMatcher();

        /** @var array{hit:?CompiledRoute,params:array<string,string>,allowed:array<string,bool>} $res */
        $res = $compiledFn($verb, $host, $path);
        if ($res['hit'] instanceof CompiledRoute) {
            return [$res['hit'], $res['params']];
        }

        $allowed = $res['allowed'];
        if ($host !== '*') {
            /** @var array{hit:?CompiledRoute,params:array<string,string>,allowed:array<string,bool>} $wild */
            $wild = $compiledFn($verb, '*', $path);
            if ($wild['hit'] instanceof CompiledRoute) {
                return [$wild['hit'], $wild['params']];
            }
            $allowed = $this->mergeAllowedVerbs($allowed, $wild['allowed']);
        }

        $this->throwMissException($verb, $path, $allowed);
    }

    /**
     * Resolve named route alias to [path, domain] tuple.
     *
     * @return array{0:string,1:?string}|null
     */
    public function resolveAlias(string $name): ?array
    {
        $idx = $this->aliasIndex();

        return $idx[$name] ?? null;
    }

    /**
     * @param array<int,array<string,array{segments:array,params:list<array{0:string,1:int}>,verbs:array<string,int>}>> $dynamicTmp
     */
    private function appendDynamicGenerationRoute(array &$dynamicTmp, CompiledRoute $route, string $verb, int $idx): void
    {
        $segments = $route->getSegments();
        $segCount = \count($segments);
        $key = $route->getPath();

        if (!isset($dynamicTmp[$segCount][$key])) {
            $dynamicTmp[$segCount][$key] = [
                'segments' => $segments,
                'params' => $this->extractDynamicParams($segments),
                'verbs' => [],
            ];
        }

        $dynamicTmp[$segCount][$key]['verbs'][$verb] = $idx;
    }

    /**
     * Build matcher closure source code expression.
     */
    private function buildMatcherCode(): string
    {
        [$routeExprs, $hosts] = $this->prepareGenerationData();

        $routeInit = "[\n";
        foreach ($routeExprs as $idx => $expr) {
            $routeInit .= "            {$idx} => {$expr},\n";
        }
        $routeInit .= '        ]';

        $hostSwitch = $this->renderHostSwitch($hosts);

        return "static function (string \$verb, string \$host, string \$path): array {\n"
            . "    static \$routes = null;\n"
            . "    if (\$routes === null) {\n"
            . "        \$routes = {$routeInit};\n"
            . "    }\n"
            . "    \$allowed = [];\n"
            . "    \$trimmed = \\trim(\$path, '/');\n"
            . "    \$segments = (\$trimmed === '') ? [] : \\explode('/', \$trimmed);\n"
            . "    \$segCount = \\count(\$segments);\n"
            . $hostSwitch
            . "    return ['hit' => null, 'params' => [], 'allowed' => \$allowed];\n"
            . '}';
    }

    /**
     * Compile generated matcher source into callable closure without eval().
     *
     * @param string $code Source that must evaluate to a Closure.
     */
    private function compileClosureFromCode(string $code): Closure
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'webrick-gen-');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temp file for matcher compilation.');
        }

        $php = "<?php return {$code};\n";
        if (\file_put_contents($tmp, $php, \LOCK_EX) === false) {
            \unlink($tmp);

            throw new \RuntimeException('Failed to write temp matcher compilation file.');
        }

        try {
            $fn = require $tmp;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to compile generated matcher source.', 0, $e);
        } finally {
            \unlink($tmp);
        }

        if (!$fn instanceof Closure) {
            throw new \RuntimeException('Generated matcher source did not return a Closure.');
        }

        return $fn;
    }

    /**
     * Build and atomically write generated matcher cache blob.
     */
    private function dumpCache(): void
    {
        $dir = \dirname($this->cacheFile);
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir {$dir}");
        }

        $code = $this->buildMatcherCode();
        $hash = \hash('xxh3', $code);

        $php = "<?php\nreturn [\n"
            . "    '" . self::H_HASH . "' => " . \var_export($hash, true) . ",\n"
            . "    '" . self::H_TS . "' => " . \var_export(\date(\DATE_ATOM), true) . ",\n"
            . "    '" . self::H_ALIAS . "' => " . $this->exportArray($this->alias) . ",\n"
            . "    '_code' => " . \var_export($code, true) . ",\n"
            . "    '_match' => {$code},\n"
            . "];\n";

        $this->writeAtomicPhpFile($this->cacheFile, $php);

        if ($this->shouldWarmOpcache()) {
            \opcache_compile_file($this->cacheFile);
        }
    }

    private function ensureCompiledMatcher(): Closure
    {
        if ($this->cacheEnabled && !$this->cacheLoaded && \is_file($this->cacheFile)) {
            $this->loadCacheBlob();
        }

        if ($this->compiledFn === null) {
            $this->compiledFn = $this->compileClosureFromCode($this->buildMatcherCode());
        }
        if (!$this->compiledFn instanceof Closure) {
            throw new \RuntimeException('Generated matcher not initialized.');
        }

        return $this->compiledFn;
    }

    /**
     * @param array<int,array{type:string,name?:string,val?:string,regex?:string,call?:callable}> $segments
     * @return list<array{0:string,1:int}>
     */
    private function extractDynamicParams(array $segments): array
    {
        $params = [];
        foreach ($segments as $i => $part) {
            if (($part['type'] ?? '') === 'var') {
                $params[] = [(string) $part['name'], $i];
            }
        }

        return $params;
    }

    /**
     * @param array<int,array<string,array{segments:array,params:list<array{0:string,1:int}>,verbs:array<string,int>}>> $dynamicTmp
     * @return array<int,list<array{segments:array,params:list<array{0:string,1:int}>,verbs:array<string,int>}>>
     */
    private function finalizeDynamicGenerationBuckets(array $dynamicTmp): array
    {
        $dynamic = [];
        foreach ($dynamicTmp as $segCount => $items) {
            $dynamic[$segCount] = \array_values($items);
        }

        return $dynamic;
    }

    /**
     * Lazy-load generated cache blob and compile matcher closure.
     */
    private function loadCacheBlob(): void
    {
        /** @var array{_hash?:string,_alias?:array<string,array{0:string,1:?string}>,_code?:string,_match?:mixed} $blob */
        $blob = require $this->cacheFile;

        $fn = $blob['_match'] ?? null;
        if (!$fn instanceof Closure) {
            throw new \RuntimeException('Generated matcher cache missing closure payload.');
        }

        if ($this->verifyCacheOnLoad) {
            $code = $blob['_code'] ?? null;
            if (!\is_string($code) || $code === '') {
                throw new \RuntimeException('Generated matcher cache missing code payload.');
            }
            if (!isset($blob[self::H_HASH])) {
                throw new \RuntimeException('Generated matcher cache missing Hash.');
            }
            $calc = \hash('xxh3', $code);
            if (!\hash_equals($blob[self::H_HASH], $calc)) {
                throw new \RuntimeException('Generated matcher cache Hash mismatch.');
            }
        }

        $this->compiledFn = $fn;
        $this->alias = $blob[self::H_ALIAS] ?? [];
        $this->cacheLoaded = true;
    }

    /**
     * @param array<string,bool> $base
     * @param array<string,bool> $incoming
     * @return array<string,bool>
     */
    private function mergeAllowedVerbs(array $base, array $incoming): array
    {
        foreach ($incoming as $k => $v) {
            if ($v) {
                $base[$k] = true;
            }
        }

        return $base;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function normalizeMatchInput(string $method, string $host, string $path): array
    {
        $verb = \strtoupper($method);
        $host = \strtolower($host);
        $path = ($path === '' ? '/' : $path);

        return [$verb, $host, $path];
    }

    /**
     * Build generation data structures:
     *  - route expressions map
     *  - host buckets containing static and dynamic maps.
     *
     * @return array{0:array<int,string>,1:array<string,array{static:array,dynamic:array}>}
     */
    private function prepareGenerationData(): array
    {
        $routeExprs = [];
        $routeIds = [];
        $hosts = [];

        foreach ($this->hostRoutes as $host => $routes) {
            $hosts[$host] = $this->prepareHostGenerationData($routes, $routeExprs, $routeIds);
        }

        return [$routeExprs, $hosts];
    }

    /**
     * @param list<CompiledRoute> $routes
     * @param array<int,string> $routeExprs
     * @param array<int,int> $routeIds
     * @return array{static:array<string,array<string,int>>,dynamic:array<int,list<array{segments:array,params:array,verbs:array<string,int>}>>}
     */
    private function prepareHostGenerationData(array $routes, array &$routeExprs, array &$routeIds): array
    {
        $static = [];
        $dynamicTmp = [];

        foreach ($routes as $r) {
            $idx = $this->routeExpressionIndex($r, $routeExprs, $routeIds);
            $verb = HttpMethodEnum::normalize($r->getMethod());

            if (!$r->isDynamic()) {
                $static[$r->getPath()][$verb] = $idx;

                continue;
            }

            $this->appendDynamicGenerationRoute($dynamicTmp, $r, $verb, $idx);
        }

        return [
            'static' => $static,
            'dynamic' => $this->finalizeDynamicGenerationBuckets($dynamicTmp),
        ];
    }

    /**
     * @param array{segments:array,params:list<array{0:string,1:int}>,verbs:array<string,int>} $entry
     */
    private function renderDynamicEntry(array $entry, string $indent): string
    {
        $cond = $this->renderDynamicEntryCondition($entry['segments'], $indent);
        $params = $this->renderDynamicEntryParams($entry['params'], $indent);

        return $indent . "        if ({$cond}) {\n" . $params;
    }

    /**
     * @param array<int,array{type:string,name?:string,val?:string,regex?:string,call?:callable}> $segments
     */
    private function renderDynamicEntryCondition(array $segments, string $indent): string
    {
        $checks = [];
        foreach ($segments as $i => $part) {
            if (($part['type'] ?? '') === 'lit') {
                $checks[] = "(\$segments[{$i}] ?? null) === " . \var_export($part['val'], true);

                continue;
            }

            if (isset($part['regex'])) {
                $checks[] = '\\preg_match(' . \var_export($part['regex'], true) . ", (string)(\$segments[{$i}] ?? '')) === 1";

                continue;
            }

            if (isset($part['call'])) {
                $checks[] = '\\call_user_func(' . \var_export($part['call'], true) . ", (string)(\$segments[{$i}] ?? ''))";
            }
        }

        return $checks === []
            ? 'true'
            : \implode(" &&\n" . $indent . '            ', $checks);
    }

    /**
     * @param list<array{0:string,1:int}> $params
     */
    private function renderDynamicEntryParams(array $params, string $indent): string
    {
        if ($params === []) {
            return $indent . "            \$params = [];\n";
        }

        $pairs = [];
        foreach ($params as [$name, $pos]) {
            $pairs[] = \var_export((string) $name, true) . " => (string)\$segments[{$pos}]";
        }

        return $indent . '            $params = [' . \implode(', ', $pairs) . "];\n";
    }

    /**
     * Render dynamic segment-count switches for a host bucket.
     *
     * @param array<int,list<array{segments:array,params:array,verbs:array<string,int>}>> $dynamic
     */
    private function renderDynamicSwitch(array $dynamic, string $indent): string
    {
        if ($dynamic === []) {
            return '';
        }

        $code = $indent . "switch (\$segCount) {\n";
        foreach ($dynamic as $segCount => $entries) {
            $code .= $indent . "    case {$segCount}:\n";
            foreach ($entries as $entry) {
                $code .= $this->renderDynamicEntry($entry, $indent);
                $code .= $this->renderVerbDispatch($entry['verbs'], $indent . '            ');
                $code .= $indent . "        }\n";
            }
            $code .= $indent . "        break;\n";
        }

        return $code . ($indent . "}\n");
    }

    /**
     * Render host-level switch block.
     *
     * @param array<string,array{static:array,dynamic:array}> $hosts
     */
    private function renderHostSwitch(array $hosts): string
    {
        if ($hosts === []) {
            return '';
        }

        $code = "    switch (\$host) {\n";
        foreach ($hosts as $host => $bucket) {
            $code .= '        case ' . \var_export($host, true) . ":\n";
            $code .= $this->renderStaticSwitch($bucket['static'], '            ');
            $code .= $this->renderDynamicSwitch($bucket['dynamic'], '            ');
            $code .= "            break;\n";
        }

        return $code . "    }\n";
    }

    /**
     * Render static path switch for a host bucket.
     *
     * @param array<string,array<string,int>> $static path => verb => routeIdx
     */
    private function renderStaticSwitch(array $static, string $indent): string
    {
        if ($static === []) {
            return '';
        }

        $code = $indent . "switch (\$path) {\n";
        foreach ($static as $path => $verbs) {
            $code .= $indent . '    case ' . \var_export($path, true) . ":\n";
            $code .= $indent . "        \$params = [];\n";
            $code .= $this->renderVerbDispatch($verbs, $indent . '        ');
            $code .= $indent . "        break;\n";
        }

        return $code . ($indent . "}\n");
    }

    /**
     * Render HTTP verb selection block for a matched route bucket.
     *
     * @param array<string,int> $verbs verb => routeIdx
     */
    private function renderVerbDispatch(array $verbs, string $indent): string
    {
        $firstIdx = (int) \reset($verbs);
        $code = $indent . "switch (\$verb) {\n";
        foreach ($verbs as $method => $idx) {
            $code .= $indent . '    case ' . \var_export($method, true) . ":\n";
            $code .= $indent . "        return ['hit' => \$routes[{$idx}], 'params' => \$params, 'allowed' => []];\n";
        }

        if (!isset($verbs[HttpMethodEnum::HEAD->value]) && isset($verbs[HttpMethodEnum::GET->value])) {
            $getIdx = $verbs[HttpMethodEnum::GET->value];
            $code .= $indent . '    case ' . \var_export(HttpMethodEnum::HEAD->value, true) . ":\n";
            $code .= $indent . "        return ['hit' => \$routes[{$getIdx}], 'params' => \$params, 'allowed' => []];\n";
        }

        $code .= $indent . '    case ' . \var_export(HttpMethodEnum::OPTIONS->value, true) . ":\n";
        $code .= $indent . "        return ['hit' => \$routes[{$firstIdx}], 'params' => \$params, 'allowed' => []];\n";
        $code .= $indent . "    default:\n";
        foreach ($verbs as $method => $_idx) {
            $code .= $indent . '        $allowed[' . \var_export($method, true) . "] = true;\n";
        }
        if (isset($verbs[HttpMethodEnum::GET->value])) {
            $code .= $indent . '        $allowed[' . \var_export(HttpMethodEnum::HEAD->value, true) . "] = true;\n";
        }
        $code .= $indent . "        break;\n";

        return $code . ($indent . "}\n");
    }

    /**
     * @param array<int,string> $routeExprs
     * @param array<int,int> $routeIds
     */
    private function routeExpressionIndex(CompiledRoute $route, array &$routeExprs, array &$routeIds): int
    {
        $objId = \spl_object_id($route);
        if (!isset($routeIds[$objId])) {
            $routeIds[$objId] = \count($routeExprs);
            $routeExprs[$routeIds[$objId]] = $this->exportRoute($route);
        }

        return $routeIds[$objId];
    }

    /**
     * @param array<string,bool> $allowed
     */
    private function throwMissException(string $verb, string $path, array $allowed): never
    {
        if ($allowed !== []) {
            throw new MethodNotAllowedException($verb, $path, \array_keys($allowed));
        }

        throw new RouteNotFoundException($verb, $path);
    }

    /**
     * Atomically write a PHP file.
     */
    private function writeAtomicPhpFile(string $file, string $php): void
    {
        $tmp = $file . '.' . \uniqid('', true) . '.tmp';
        if (\file_put_contents($tmp, $php, \LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write cache temp file {$tmp}");
        }
        \chmod($tmp, 0664);
        if (!\rename($tmp, $file)) {
            \unlink($tmp);

            throw new \RuntimeException("Failed to move cache file into place {$file}");
        }
    }
}
