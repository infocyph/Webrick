<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Closure;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * High-speed matcher (single-file cache variant)
 * ─────────────────────────────────────────────────────────────
 *  • O(1) hash-look-ups for *all* static paths
 *      hosts[host]['static'][path][verb] = CompiledRoute
 *  • Radix-style trie for dynamic placeholders
 *  • Optional single-file cache (enableCache + finalize)
 */
final class MergedMatcher implements MatcherInterface
{
    /* array key constants (readability + typo-safety) */
    private const K_STATIC = 'static';
    private const K_TRIE = 'trie';
    private const K_CHILDREN = 'children';
    private const K_PARAM = 'param';
    private const K_ROUTES = 'routes';

    /* cache blob keys */
    private const H_HASH = '_hash';
    private const H_DATA = '_data';

    /*──────────── factory ────────────*/
    public static function make(): self
    {
        return new self();
    }

    private function __construct()
    {
    }

    /*──────────── cache API ──────────*/
    public function enableCache(string $cacheLocation): self
    {
        $this->cacheEnabled = true;
        $this->cacheFile = $cacheLocation;
        return $this;
    }

    /** Optional: verify hash on load (useful in dev / CI) */
    public function verifyCacheOnLoad(bool $enable = true): self
    {
        $this->verifyCacheOnLoad = $enable;
        return $this;
    }

    /** Hint for RouterKernel: true if we can skip compiling and boot from cache. */
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
            // free memory; table will be lazy-loaded on first match()
            $this->hosts = [];
            $this->cacheLoaded = false;
        }
        $this->finalized = true;
    }

    /*──────────── Route registration ──────────*/
    public function add(CompiledRoute $route): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot add routes after finalize().');
        }

        $host = $this->canonicalRouteHost($route->getDomain());
        $verb = \strtoupper($route->getMethod());

        $this->hosts[$host] ??= [self::K_STATIC => [], self::K_TRIE => $this->newNode()];

        $route->isDynamic()
            ? $this->insertDynamic($host, $verb, $route)
            : $this->insertStatic($host, $verb, $route);
    }

    /*──────────── Runtime match ───────────────*/
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

        /* ① full-path static table (host then wildcard) */
        if ($hit = $this->matchStatic($host, $verb, $path, $allowedSet)) {
            return $hit;
        }

        /* ② trie descent (host then wildcard) */
        if ($hit = $this->matchTrie($host, $verb, $path, $allowedSet)) {
            return $hit;
        }

        /* ③ verdict */
        if ($allowedSet !== []) {
            throw new MethodNotAllowedException($verb, $path, \array_keys($allowedSet));
        }
        throw new RouteNotFoundException($verb, $path);
    }

    /*---------------------------------------------------------------------
     *  Build-time helpers
     *-------------------------------------------------------------------*/
    /** Canonicalize route domain to match RouterKernel's normalization. */
    private function canonicalRouteHost(?string $raw): string
    {
        if ($raw === null || $raw === '' || $raw === '*') {
            return '*';
        }
        $host = \rtrim(\strtolower($raw), '.');

        // disallow spaces/control chars early
        if (\preg_match('/[\x00-\x20]/', $host)) {
            throw new \InvalidArgumentException("Illegal host name: {$raw}");
        }

        // IDN → ASCII (punycode) if available and not already punycoded
        if (\function_exists('idn_to_ascii') && !\str_contains($host, 'xn--')) {
            $ascii = @\idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException("Invalid IDN host name: {$raw}");
            }
            $host = $ascii;
        }

        // ensure printable ASCII
        if (!\preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException("Host contains non-ASCII bytes: {$raw}");
        }

        return $host;
    }

    /*—— static route insertion ——*/
    private function insertStatic(string $host, string $verb, CompiledRoute $r): void
    {
        $path = $r->getPath();
        $table = &$this->hosts[$host][self::K_STATIC];

        if (isset($table[$path][$verb])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }
        $table[$path][$verb] = $r;
    }

    /*—— dynamic route insertion ——*/
    private function insertDynamic(string $host, string $verb, CompiledRoute $r): void
    {
        $node = &$this->hosts[$host][self::K_TRIE];
        foreach ($r->getSegments() as $seg) {
            if ($seg['type'] === 'lit') {
                $node = &$this->literalChild($node, $seg['val']);
            } else {
                $node = &$this->paramChild($node, $seg);
            }
        }
        if (isset($node[self::K_ROUTES][$verb])) {
            throw new \LogicException("Duplicate dynamic route {$verb} {$host}{$r->getPath()}");
        }
        $node[self::K_ROUTES][$verb] = $r;
    }

    private function newNode(): array
    {
        return [self::K_CHILDREN => [], self::K_PARAM => null, self::K_ROUTES => []];
    }

    private function &literalChild(array &$n, string $s): array
    {
        $n[self::K_CHILDREN][$s] ??= $this->newNode();
        return $n[self::K_CHILDREN][$s];
    }

    private function &paramChild(array &$n, array $spec): array
    {
        if ($n[self::K_PARAM] !== null) {
            if ($n[self::K_PARAM]['name'] !== $spec['name'] || $n[self::K_PARAM]['regex'] !== $spec['regex']) {
                throw new \LogicException("Conflicting placeholders at same depth");
            }
            return $n[self::K_PARAM]['node'];
        }
        $n[self::K_PARAM] = ['name' => $spec['name'], 'regex' => $spec['regex'], 'node' => $this->newNode()];
        return $n[self::K_PARAM]['node'];
    }

    /*---------------------------------------------------------------------
     *  Matching helpers
     *-------------------------------------------------------------------*/
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

            // gather for 405 (+implicit HEAD when GET exists)
            $this->addAllowedFromMap($map, $allowedSet);
        }
        return null;
    }

    /*—— radix trie descent (try host + wildcard) ——*/
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
            if ($this->walk($root, $segments, 0, $verb, $params, $allowedSet, $hit)) {
                return $hit;   // [$route,$params]
            }
        }
        return null;
    }

    private function walk(
        array $node,
        array $seg,
        int $i,
        string $verb,
        array &$params,
        array &$allowedSet,
        ?array &$hit,
    ): bool {
        if ($i === \count($seg)) {
            return $this->leafPick($node, $verb, $params, $allowedSet, $hit);
        }

        $piece = $seg[$i];

        if (isset($node[self::K_CHILDREN][$piece]) &&
            $this->walk($node[self::K_CHILDREN][$piece], $seg, $i + 1, $verb, $params, $allowedSet, $hit)) {
            return true;
        }

        $p = $node[self::K_PARAM];
        if ($p && \preg_match($p['regex'], $piece) === 1) {
            $params[$p['name']] = $piece; // push
            $ok = $this->walk($p['node'], $seg, $i + 1, $verb, $params, $allowedSet, $hit);
            unset($params[$p['name']]);   // pop
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    private function leafPick(
        array $node,
        string $verb,
        array $params,
        array &$allowedSet,
        ?array &$hit,
    ): bool {
        $routes = $node[self::K_ROUTES] ?? [];

        if ($r = $this->pickVerbRoute($routes, $verb)) {
            $hit = [$r, $params];
            return true;
        }

        if ($routes) {
            $this->addAllowedFromRoutes($routes, $allowedSet);
        }
        return false;
    }

    /** Unified verb selection for both static map and trie-leaf buckets. */
    private function pickVerbRoute(array $buckets, string $verb): ?CompiledRoute
    {
        if ($verb === 'OPTIONS' && $buckets) {
            /** @var ?CompiledRoute $first */
            $first = \reset($buckets);
            return $first instanceof CompiledRoute ? $first : null;
        }
        if (isset($buckets[$verb])) {
            return $buckets[$verb];
        }
        if ($verb === 'HEAD' && isset($buckets['GET'])) {
            return $buckets['GET'];
        }
        return null;
    }

    /* allowed-set helpers (no merges/uniques) */
    private function addAllowedFromMap(array $map, array &$set): void
    {
        foreach ($map as $verb => $_route) {
            $set[$verb] = true;
        }
        if (isset($map['GET'])) {
            $set['HEAD'] = true; // implicit
        }
    }

    private function addAllowedFromRoutes(array $routes, array &$set): void
    {
        foreach ($routes as $verb => $_route) {
            $set[$verb] = true;
        }
        if (isset($routes['GET'])) {
            $set['HEAD'] = true; // implicit
        }
    }

    /* utils */
    private function explodePath(string $p): array
    {
        $t = \trim($p, '/');
        return $t === '' ? [] : \explode('/', $t);
    }

    /*---------------------------------------------------------------------
     *  Cache export helpers (unchanged API)
     *-------------------------------------------------------------------*/
    private function dumpCache(): void
    {
        $dir = \dirname($this->cacheFile);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir {$dir}");
        }

        /* ① build payload + CRC */
        $payload = $this->hosts;
        $crc = \hash('xxh3', \json_encode($payload, \JSON_THROW_ON_ERROR));

        $php = "<?php\nreturn [\n"
            . "    '" . self::H_HASH . "'  => " . \var_export($crc, true) . ",\n"
            . "    '" . self::H_DATA . "' => " . $this->exportArray($payload) . ",\n"
            . "];\n";

        $tmp = $this->cacheFile . '.' . \uniqid('', true) . '.tmp';
        \file_put_contents($tmp, $php, \LOCK_EX);
        @\chmod($tmp, 0664);
        @\rename($tmp, $this->cacheFile);

        /* ② pre-compile into OPcache */
        if (\function_exists('opcache_compile_file')) {
            @\opcache_compile_file($this->cacheFile);
        }
    }

    private function exportArray(array $a, int $depth = 0): string
    {
        $indent = \str_repeat('    ', $depth);
        $out = "[\n";
        foreach ($a as $k => $v) {
            $out .= $indent . '    ' . \var_export($k, true) . ' => ';
            $out .= \is_array($v) ? $this->exportArray($v, $depth + 1)
                : $this->exportValue($v, $depth + 1);
            $out .= ",\n";
        }
        return $indent . \rtrim($out, ",\n") . "\n" . $indent . "]";
    }

    private function exportValue(mixed $v, int $d): string
    {
        return $v instanceof CompiledRoute
            ? $this->exportRoute($v)
            : (\is_array($v) ? $this->exportArray($v, $d) : \var_export($v, true));
    }

    private function exportRoute(CompiledRoute $r): string
    {
        if (!$this->handlerHasClosure($r->getHandler())) {
            return 'new \\' . CompiledRoute::class . '('
                . \var_export($r->getMethod(), true) . ', '
                . \var_export($r->getPath(), true) . ', '
                . \var_export($r->getHandler(), true) . ', '
                . \var_export($r->getDomain(), true) . ', '
                . \var_export($r->getMiddlewares(), true) . ', '
                . \var_export($r->getName(), true) . ', '
                . ($r->isDynamic() ? 'true' : 'false') . ', '
                . \var_export($r->getRegex(), true) . ', '
                . \var_export($r->getVariables(), true) . ', '
                . \var_export($r->getIndex(), true) . ', '
                . \var_export($r->getCorsPolicy(), true) . ', '
                . \var_export($r->getSegments(), true)
                . ')';
        }
        return '\\' . ValueSerializer::class . '::unserialize('
            . \var_export(ValueSerializer::serialize($r), true) . ')';
    }

    private function handlerHasClosure(callable|array|string $h): bool
    {
        return $h instanceof Closure
            || (\is_array($h) && (($h[0] ?? null) instanceof Closure || ($h[1] ?? null) instanceof Closure));
    }

    /*---------------------------------------------------------------------
     *  State
     *-------------------------------------------------------------------*/
    /** host-bucket data: [$host]['static'|'trie'] */
    private array $hosts = [];

    private bool $cacheEnabled = false;
    private string $cacheFile = '';
    private bool $cacheLoaded = false;
    private bool $finalized = false;
    private bool $verifyCacheOnLoad = false;
}
