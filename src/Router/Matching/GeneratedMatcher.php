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
 *
 * @phpstan-type AliasIndex array<string, array{0:string,1:?string}>
 * @phpstan-type SegmentLit array{type:'lit',val:string}
 * @phpstan-type SegmentVar array{type:'var',name:string,regex?:string,call?:callable-string}
 * @phpstan-type SegmentSpec SegmentLit|SegmentVar
 * @phpstan-type ParamRef array{0:string,1:int}
 * @phpstan-type DynamicEntry array{segments:list<SegmentSpec>,params:list<ParamRef>,verbs:array<string,int>}
 * @phpstan-type DynamicTmp array<int,array<string,DynamicEntry>>
 * @phpstan-type DynamicBuckets array<int,list<DynamicEntry>>
 * @phpstan-type HostGen array{static:array<string,array<string,int>>,dynamic:DynamicBuckets}
 * @phpstan-type HostsGen array<string,HostGen>
 */
final class GeneratedMatcher extends AbstractMatcher implements MatcherInterface
{
    use MatcherCacheLifecycleTrait;
    use MatcherFactoryTrait;

    /**
     * Route alias index: name => [path, domain|null].
     *
     * @var AliasIndex
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

    public function add(CompiledRoute $route): void
    {
        [$host] = matcher_prepare_route_registration(
            $this->finalized,
            $this->guard,
            $this->canonicalRouteHost(...),
            $route,
        );
        $this->hostRoutes[$host][] = $route;
        matcher_capture_route_alias($this->alias, $route);
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
                $this->resetCachedState();
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
     * @param DynamicTmp $dynamicTmp
     */
    private function appendDynamicGenerationRoute(array &$dynamicTmp, CompiledRoute $route, string $verb, int $idx): void
    {
        $segments = $this->normalizeSegments($route->getSegments());
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
        $hash = \hash('xxh128', $code);

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
        $this->ensureCacheLoaded();

        if ($this->compiledFn === null) {
            $this->compiledFn = $this->compileClosureFromCode($this->buildMatcherCode());
        }

        return $this->compiledFn;
    }

    /**
     * @param list<SegmentSpec> $segments
     * @return list<ParamRef>
     */
    private function extractDynamicParams(array $segments): array
    {
        $params = [];
        foreach ($segments as $i => $part) {
            if ($part['type'] === 'var') {
                $params[] = [$part['name'], $i];
            }
        }

        return $params;
    }

    /**
     * @phpstan-param DynamicTmp $dynamicTmp
     * @phpstan-return DynamicBuckets
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
            $calc = \hash('xxh128', $code);
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
     * @return list<SegmentSpec>
     */
    private function normalizeSegments(mixed $segments): array
    {
        if (!\is_array($segments)) {
            return [];
        }

        $normalized = [];
        foreach ($segments as $segment) {
            if (!\is_array($segment) || !\is_string($segment['type'] ?? null)) {
                continue;
            }

            if ($segment['type'] === 'lit' && \is_string($segment['val'] ?? null)) {
                $normalized[] = ['type' => 'lit', 'val' => $segment['val']];

                continue;
            }

            if ($segment['type'] !== 'var' || !\is_string($segment['name'] ?? null)) {
                continue;
            }

            $entry = ['type' => 'var', 'name' => $segment['name']];
            if (\is_string($segment['regex'] ?? null)) {
                $entry['regex'] = $segment['regex'];
            }

            $call = $segment['call'] ?? null;
            if (\is_string($call) && \is_callable($call)) {
                $entry['call'] = $call;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * Build generation data structures:
     *  - route expressions map
     *  - host buckets containing static and dynamic maps.
     *
     * @return array{0:array<int,string>,1:HostsGen}
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
     * @phpstan-param list<CompiledRoute> $routes
     * @phpstan-param array<int,string> $routeExprs
     * @phpstan-param array<int,int> $routeIds
     * @phpstan-return HostGen
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
     * @param DynamicEntry $entry
     */
    private function renderDynamicEntry(array $entry, string $indent): string
    {
        $cond = $this->renderDynamicEntryCondition($entry['segments'], $indent);
        $params = $this->renderDynamicEntryParams($entry['params'], $indent);

        return $indent . "        if ({$cond}) {\n" . $params;
    }

    /**
     * @param list<SegmentSpec> $segments
     */
    private function renderDynamicEntryCondition(array $segments, string $indent): string
    {
        return generated_matcher_render_dynamic_entry_condition($segments, $indent);
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
     * @param DynamicBuckets $dynamic
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
     * @param HostsGen $hosts
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
        return generated_matcher_render_verb_dispatch($verbs, $indent);
    }

    private function resetCachedState(): void
    {
        [$this->hostRoutes, $this->guard] = [[], []];
        $this->compiledFn = null;
        $this->cacheLoaded = false;
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
        sharded_matcher_write_atomic_php_file($file, $php);
    }
}
