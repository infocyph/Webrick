<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Generated matcher compiled from the canonical matcher index. */
final class GeneratedMatcher extends AbstractMatcher implements MatcherInterface
{
    use MatcherCacheLifecycleTrait;
    use MatcherFactoryTrait;

    private const int INDEX_CACHE_VERSION = 6;

    /** @var array<string,array{0:string,1:?string}> */
    private array $alias = [];

    private bool $cacheEnabled = false;

    private string $cacheFile = '';

    private bool $cacheLoaded = false;

    private bool $cacheWriteEnabled = false;

    /** @var Closure(string,string,string,bool):(int|array{0:int,1:array<string,string>}|MatchOutcome)|null */
    private ?Closure $compiledFn = null;

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
        $this->index->add($this->canonicalRouteHost($route->getDomain()), $route);
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
        } elseif ($this->compiledFn === null) {
            $this->compiledFn = $this->compileClosureFromCode($this->buildMatcherCode());
        }

        $this->finalized = true;
    }

    public function match(string $method, string $host, string $path): array
    {
        $verb = HttpMethodEnum::normalize($method);
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
        $fn = $this->compiledFn ?? throw new \LogicException('Generated matcher must be finalized before compiled dispatch.');

        return $fn($method, $host, $path, true);
    }

    public function matchOutcome(string $method, string $host, string $path): MatchOutcome
    {
        $this->finalize();
        $fn = $this->compiledFn ?? throw new \LogicException('Generated matcher was not finalized.');
        /** @var MatchOutcome $outcome */
        $outcome = $fn($method, $host, $path, false);

        return $outcome;
    }

    public function resolveAlias(string $name): ?array
    {
        $index = $this->aliasIndex();

        return $index[$name] ?? null;
    }

    private static function removeTemporaryMatcherFile(string $file): void
    {
        if (is_file($file) && !unlink($file)) {
            throw new \RuntimeException("Failed to remove temporary matcher file {$file}");
        }
    }

    private function bootIndex(): void
    {
        $this->index ??= new CanonicalMatcherIndex();
    }

    private function buildMatcherCode(): string
    {
        [$payloads, $hosts] = $this->generationData();
        $payloadCode = $this->exportArray($payloads, 2);
        $outcome = '\\' . MatchOutcome::class;
        $options = var_export(HttpMethodEnum::OPTIONS->value, true);

        $staticSwitch = "    switch (\$host) {\n";
        foreach ($hosts as $host => $index) {
            if ($host === '*' || $index['static'] === []) {
                continue;
            }
            $staticSwitch .= '        case ' . var_export($host, true) . ":\n";
            $staticSwitch .= $this->renderStaticIndex($index['static'], '            ');
            $staticSwitch .= "            break;\n";
        }
        $staticSwitch .= "    }\n";
        if (isset($hosts['*'])) {
            $staticSwitch .= $this->renderStaticIndex($hosts['*']['static'], '    ');
        }

        $dynamicSwitch = '';
        if (array_any($hosts, static fn(array $index): bool => $index['dynamic'] !== [])) {
            $dynamicSwitch .= "    \$trimmed = \\trim(\$path, '/');\n";
            $dynamicSwitch .= "    \$segments = \$trimmed === '' ? [] : \\explode('/', \$trimmed);\n";
            $dynamicSwitch .= "    \$segCount = \\count(\$segments);\n";
            $dynamicSwitch .= "    \$prefix = \$segments[0] ?? '';\n";
            $dynamicSwitch .= "    switch (\$host) {\n";
            foreach ($hosts as $host => $index) {
                if ($host === '*' || $index['dynamic'] === []) {
                    continue;
                }
                $dynamicSwitch .= '        case ' . var_export($host, true) . ":\n";
                $dynamicSwitch .= $this->renderDynamicIndex($index['dynamic'], '            ');
                $dynamicSwitch .= "            break;\n";
            }
            $dynamicSwitch .= "    }\n";
            if (isset($hosts['*'])) {
                $dynamicSwitch .= $this->renderDynamicIndex($hosts['*']['dynamic'], '    ');
            }
        }

        return "static function (string \$verb, string \$host, string \$path, bool \$compact) {\n"
            . "    static \$routePayloads = {$payloadCode};\n"
            . "    static \$routes = [];\n"
            . "    \$allowed = [];\n"
            . $staticSwitch
            . $dynamicSwitch
            . "    if (\$allowed !== []) {\n"
            . "        \$methods = \\array_keys(\$allowed);\n"
            . "        return \$verb === {$options} ? {$outcome}::autoOptions(\$methods) : {$outcome}::methodNotAllowed(\$methods);\n"
            . "    }\n"
            . "    return {$outcome}::notFound();\n"
            . '}';
    }

    /** @param list<string> $middleware */
    private function cacheHash(string $code, array $middleware): string
    {
        return hash('xxh128', serialize([$code, $middleware]));
    }

    private function compileClosureFromCode(string $code): Closure
    {
        $file = tempnam(sys_get_temp_dir(), 'webrick-gen-');
        if ($file === false) {
            throw new \RuntimeException('Failed to allocate temporary matcher file.');
        }
        if (file_put_contents($file, "<?php return {$code};\n", LOCK_EX) === false) {
            self::removeTemporaryMatcherFile($file);

            throw new \RuntimeException('Failed to write generated matcher source.');
        }

        try {
            $fn = require $file;
        } finally {
            self::removeTemporaryMatcherFile($file);
        }
        if (!$fn instanceof Closure) {
            throw new \RuntimeException('Generated matcher source did not return a Closure.');
        }

        return $fn;
    }

    private function dumpCache(): void
    {
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create cache dir {$directory}");
        }

        $code = $this->buildMatcherCode();
        $middleware = array_keys($this->middlewareRequirements);
        $hash = $this->cacheHash($code, $middleware);
        $php = "<?php\nreturn [\n"
            . "    '_version' => " . self::INDEX_CACHE_VERSION . ",\n"
            . "    '_hash' => " . var_export($hash, true) . ",\n"
            . "    '_alias' => " . $this->exportArray($this->alias) . ",\n"
            . "    '_middleware' => " . $this->exportArray($middleware) . ",\n"
            . "    '_code' => " . var_export($code, true) . ",\n"
            . "    '_match' => {$code},\n"
            . "];\n";

        matcher_write_validated_atomic_php_file(
            $this->cacheFile,
            $php,
            static function (array $blob) use ($hash): void {
                if (($blob['_version'] ?? null) !== self::INDEX_CACHE_VERSION || !($blob['_match'] ?? null) instanceof Closure) {
                    throw new \UnexpectedValueException('Generated matcher cache is invalid.');
                }
                $code = $blob['_code'] ?? null;
                $middleware = matcher_normalize_middleware_requirements($blob['_middleware'] ?? []);
                if (!is_string($code) || ($blob['_hash'] ?? null) !== $hash || hash('xxh128', serialize([$code, $middleware])) !== $hash) {
                    throw new \UnexpectedValueException('Generated matcher cache hash mismatch.');
                }
            },
        );

        if ($this->shouldWarmOpcache()) {
            opcache_compile_file($this->cacheFile);
        }
    }

    /**
     * @return array{0:array<int,mixed>,1:array<string,array{static:array<string,array<string,int>>,dynamic:array<int,array<string,array<string,array{segments:list<array<string,mixed>>,verbs:array<string,int>}>>>}>}
     */
    private function generationData(): array
    {
        $payloads = [];
        $hosts = [];

        foreach ($this->index->hosts() as $host => $index) {
            $hosts[$host] = ['static' => [], 'dynamic' => []];
            foreach ($index['static'] as $path => $verbs) {
                foreach ($verbs as $verb => $route) {
                    $hosts[$host]['static'][$path][$verb] = $this->routeIndex($route, $payloads);
                }
            }
            foreach ($index['dynamic'] as $count => $prefixes) {
                foreach ($prefixes as $prefix => $entries) {
                    foreach ($entries as $path => $entry) {
                        $mapped = ['segments' => $entry['segments'], 'verbs' => []];
                        foreach ($entry['verbs'] as $verb => $route) {
                            $mapped['verbs'][$verb] = $this->routeIndex($route, $payloads);
                        }
                        $hosts[$host]['dynamic'][$count][$prefix][$path] = $mapped;
                    }
                }
            }
        }

        return [$payloads, $hosts];
    }

    private function loadCacheBlob(): void
    {
        if ($this->cacheLoaded) {
            return;
        }

        /** @var array<string,mixed> $blob */
        $blob = require $this->cacheFile;
        if (($blob['_version'] ?? null) !== self::INDEX_CACHE_VERSION || !($blob['_match'] ?? null) instanceof Closure) {
            throw new \RuntimeException('Stale generated route cache. Rebuild the route cache.');
        }
        if ($this->verifyCacheOnLoad) {
            $code = $blob['_code'] ?? null;
            $middleware = matcher_normalize_middleware_requirements($blob['_middleware'] ?? []);
            $stored = $blob['_hash'] ?? null;
            if (!is_string($code) || !is_string($stored) || !hash_equals($stored, $this->cacheHash($code, $middleware))) {
                throw new \RuntimeException('Generated route cache hash mismatch.');
            }
        }

        $this->compiledFn = $blob['_match'];
        $this->alias = matcher_normalize_alias_pairs($blob['_alias'] ?? []);
        $this->middlewareRequirements = array_fill_keys(
            matcher_normalize_middleware_requirements($blob['_middleware'] ?? []),
            true,
        );
        $this->cacheLoaded = true;
    }

    /** @param list<array<string,mixed>> $segments */
    private function renderCondition(array $segments, string $indent): string
    {
        $checks = [];
        foreach ($segments as $index => $segment) {
            if (($segment['type'] ?? null) === 'lit') {
                $checks[] = "(\$segments[{$index}] ?? null) === " . var_export($segment['val'], true);

                continue;
            }
            if (isset($segment['regex'])) {
                $checks[] = '\\preg_match(' . var_export($segment['regex'], true)
                    . ", (string)(\$segments[{$index}] ?? '')) === 1";

                continue;
            }
            /** @var callable-string $call */
            $call = $segment['call'];
            $checks[] = '(bool)(' . var_export($call, true) . ")((string)(\$segments[{$index}] ?? ''))";
        }

        return $checks === [] ? 'true' : implode(" &&\n" . $indent, $checks);
    }

    /** @param array<string,array{segments:list<array<string,mixed>>,verbs:array<string,int>}> $entries */
    private function renderDynamicEntries(array $entries, string $indent): string
    {
        $code = '';
        foreach ($entries as $entry) {
            $condition = $this->renderCondition($entry['segments'], $indent . '        ');
            $code .= $indent . "if ({$condition}) {\n";
            $code .= $indent . '    $params = ' . $this->renderParams($entry['segments']) . ";\n";
            $code .= $this->renderVerbDispatch($entry['verbs'], $indent . '    ', true);
            $code .= $indent . "}\n";
        }

        return $code;
    }

    /** @param array<int,array<string,array<string,array{segments:list<array<string,mixed>>,verbs:array<string,int>}>>> $dynamic */
    private function renderDynamicIndex(array $dynamic, string $indent): string
    {
        if ($dynamic === []) {
            return '';
        }

        $code = $indent . "switch (\$segCount) {\n";
        foreach ($dynamic as $count => $prefixes) {
            $code .= $indent . "    case {$count}:\n";
            $literal = array_filter($prefixes, static fn(string $key): bool => $key !== '*', ARRAY_FILTER_USE_KEY);
            if ($literal !== []) {
                $code .= $indent . "        switch (\$prefix) {\n";
                foreach ($literal as $prefix => $entries) {
                    $code .= $indent . '            case ' . var_export($prefix, true) . ":\n";
                    $code .= $this->renderDynamicEntries($entries, $indent . '                ');
                    $code .= $indent . "                break;\n";
                }
                $code .= $indent . "        }\n";
            }
            if (isset($prefixes['*'])) {
                $code .= $this->renderDynamicEntries($prefixes['*'], $indent . '        ');
            }
            $code .= $indent . "        break;\n";
        }

        return $code . $indent . "}\n";
    }

    /** @param list<array<string,mixed>> $segments */
    private function renderParams(array $segments): string
    {
        $pairs = [];
        foreach ($segments as $index => $segment) {
            if (($segment['type'] ?? null) === 'var') {
                $pairs[] = var_export($segment['name'], true) . " => (string)\$segments[{$index}]";
            }
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    /** @param array<string,array<string,int>> $static */
    private function renderStaticIndex(array $static, string $indent): string
    {
        if ($static === []) {
            return '';
        }

        $code = $indent . "switch (\$path) {\n";
        foreach ($static as $path => $verbs) {
            $code .= $indent . '    case ' . var_export($path, true) . ":\n";
            $code .= $this->renderVerbDispatch($verbs, $indent . '        ', false);
            $code .= $indent . "        break;\n";
        }

        return $code . $indent . "}\n";
    }

    /** @param array<string,int> $verbs */
    private function renderVerbDispatch(array $verbs, string $indent, bool $hasParams): string
    {
        $materialize = '\\' . __NAMESPACE__ . '\\matcher_materialize_cached_route';
        $outcome = '\\' . MatchOutcome::class;
        $params = $hasParams ? '$params' : '[]';
        $code = $indent . "switch (\$verb) {\n";
        foreach ($verbs as $method => $index) {
            $code .= $indent . '    case ' . var_export($method, true) . ":\n";
            $code .= $indent . "        if (\$compact) {\n";
            $code .= $indent . '            return ' . ($hasParams ? '[' . $index . ', $params]' : (string) $index) . ";\n";
            $code .= $indent . "        }\n";
            $code .= $indent . '        return ' . $outcome . '::found(($routes[' . $index
                . '] ??= ' . $materialize . '($routePayloads[' . $index . '])), ' . $params . ");\n";
        }
        if (!isset($verbs[HttpMethodEnum::HEAD->value]) && isset($verbs[HttpMethodEnum::GET->value])) {
            $index = $verbs[HttpMethodEnum::GET->value];
            $code .= $indent . '    case ' . var_export(HttpMethodEnum::HEAD->value, true) . ":\n";
            $code .= $indent . "        if (\$compact) {\n";
            $code .= $indent . '            return ' . ($hasParams ? '[' . $index . ', $params]' : (string) $index) . ";\n";
            $code .= $indent . "        }\n";
            $code .= $indent . '        return ' . $outcome . '::found(($routes[' . $index
                . '] ??= ' . $materialize . '($routePayloads[' . $index . '])), ' . $params . ", true);\n";
        }
        $code .= $indent . "    default:\n";
        foreach ($verbs as $method => $_index) {
            $code .= $indent . '        $allowed[' . var_export($method, true) . "] = true;\n";
        }
        if (isset($verbs[HttpMethodEnum::GET->value])) {
            $code .= $indent . '        $allowed[' . var_export(HttpMethodEnum::HEAD->value, true) . "] = true;\n";
        }

        return $code . $indent . "}\n";
    }

    /** @param array<int,mixed> $payloads */
    private function routeIndex(mixed $route, array &$payloads): int
    {
        $index = $route instanceof CompiledRoute ? $route->getIndex() : ExecutableRoutePayload::routeIndex($route);
        if ($index === null) {
            throw new \UnexpectedValueException('Generated matcher route is missing its compiled index.');
        }
        if (!array_key_exists($index, $payloads)) {
            $payloads[$index] = $route instanceof CompiledRoute
                ? MatcherCachePayloadNormalizer::normalize($route)
                : $route;
        }

        return $index;
    }
}
