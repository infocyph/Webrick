<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Router\Route\CompiledRoute;

require_once __DIR__ . '/matcher_functions.php';

/**
 * Shared matcher utilities and small toolkit used by concrete matchers.
 *
 * This abstract class centralises common behaviour required by different
 * matcher implementations:
 *  - canonicalisation of host names (IDN handling, validation, trimming)
 *  - helpers for verb selection and allowed-set aggregation
 *  - trie node construction, insertion and walking utilities for dynamic route
 *    matching
 *  - serialization/export helpers used when producing cache blobs
 *
 * It does not implement a matching algorithm itself but provides reusable
 * primitives to build and traverse route tries and to export compiled routes.
 *
 * @phpstan-type TrieParamSpec array{name:string,regex?:string|null,call?:callable-string|null}
 * @phpstan-type TrieParamNode array{name:string,regex?:string|null,call?:callable-string|null,node:array<string,mixed>}
 */
abstract class AbstractMatcher
{
    protected const CACHE_FORMAT_VERSION = 4;

    protected const F_ALIASES = '__aliases.php';

    protected const H_ALIAS = '_alias';

    protected const H_DATA = '_data';

    /* Header / cache blob keys */
    protected const H_HASH = '_hash';

    protected const H_MIDDLEWARE = '_middleware';

    protected const H_TS = '_ts';

    protected const H_VERSION = '_version';

    protected const K_CHILDREN = 'children';

    protected const K_PARAM = 'param';

    protected const K_ROUTES = 'routes';

    /* Shared node keys used in trie structures */
    protected const K_STATIC = 'static';

    protected const K_TRIE = 'trie';

    /**
     * When true the matcher will perform an integrity verification when loading
     * caches. Default false.
     */
    protected bool $verifyCacheOnLoad = false;

    /** @var array<string, CompiledRoute> */
    private array $materializedRoutes = [];

    /**
     * Optional hook for kernels; concrete matchers may override to indicate
     * whether they can boot from a persisted cache.
     *
     * @return bool True when the matcher supports cache booting; default false.
     */
    public function canBootFromCache(): bool
    {
        return false;
    }

    /**
     * Enable or disable cache verification on load.
     *
     * @param bool $enable True to enable verification, false to disable.
     * @return static Fluent self.
     */
    public function verifyCacheOnLoad(bool $enable = true): static
    {
        $this->verifyCacheOnLoad = $enable;

        return $this;
    }

    /**
     * Ensure and return a literal child node for the given segment.
     *
     * This returns a reference to the child node so callers can mutate it
     * directly when inserting further segments.
     *
     * @param array<string,mixed> $node Parent node (by reference)
     * @param string $seg Literal segment value
     * @return array<string,mixed> Child node (reference)
     */
    protected function &trieLiteralChild(array &$node, string $seg): array
    {
        $this->ensureNode($node);
        $rawChildren = $node[self::K_CHILDREN];
        if (!\is_array($rawChildren)) {
            $rawChildren = [];
        }
        $children = [];
        foreach ($rawChildren as $key => $child) {
            if (\is_string($key) && \is_array($child)) {
                $children[$key] = $this->normalizeNodeArray($child);
            }
        }
        $children[$seg] ??= $this->newNode();
        $node[self::K_CHILDREN] = $children;

        return $this->childNodeRef($node[self::K_CHILDREN], $seg);
    }

    /**
     * Ensure and return the parameter child node for a variable segment spec.
     *
     * The $spec array must contain a 'name' and either 'regex' or 'call'.
     * If a parameter node already exists at this depth it must match the same
     * name and rule; otherwise a LogicException is thrown to prevent ambiguous
     * placeholder definitions.
     *
     * Returns a reference to the node slot used for subsequent insertions.
     *
     * @param array<string,mixed> $node Parent node (by reference)
     * @param TrieParamSpec $spec Parameter spec
     * @return array<string,mixed> Child node (reference)
     *
     * @throws \LogicException When placeholders conflict at the same trie depth.
     */
    protected function &trieParamChild(array &$node, array $spec): array
    {
        $this->ensureNode($node);
        $ruleKey = $this->paramRuleKey($spec); // 'regex' or 'call'

        if (\is_array($node[self::K_PARAM] ?? null)) {
            /** @var array<string,mixed> $cur */
            $cur = $node[self::K_PARAM];
            if (
                ($cur['name'] ?? null) !== $spec['name']
                || ($cur[$ruleKey] ?? null) !== ($spec[$ruleKey] ?? null)
            ) {
                throw new \LogicException('Conflicting placeholders at same depth');
            }

            $paramNode = $node[self::K_PARAM]['node'] ?? null;
            $node[self::K_PARAM]['node'] = \is_array($paramNode)
                ? $this->normalizeNodeArray($paramNode)
                : $this->newNode();

            return $node[self::K_PARAM]['node'];
        }

        $node[self::K_PARAM] = [
            'name' => $spec['name'],
            'regex' => $spec['regex'] ?? null,
            'call' => $spec['call'] ?? null,
            'node' => $this->newNode(),
        ];

        return $node[self::K_PARAM]['node'];
    }

    /**
     * Populate an allowed-set map from a verb => route map.
     *
     * Adds HEAD automatically when GET is present. Uses bit-set-style map where
     * keys are verbs and values are true.
     *
     * @param array<string,mixed> $map Verb => route-like map (values not inspected)
     * @param array<string,bool> $set Map being populated (by reference)
     */
    protected function addAllowedFromMap(array $map, array &$set): void
    {
        foreach ($map as $verb => $_route) {
            $set[$verb] = true;
        }
        if (isset($map[HttpMethodEnum::GET->value])) {
            $set[HttpMethodEnum::HEAD->value] = true;
        }
    }

    /**
     * Populate an allowed-set map from an array of CompiledRoute entries.
     *
     * Equivalent to addAllowedFromMap but provided for semantic clarity when the
     * source is an array of route instances.
     *
     * @param array<string,CompiledRoute|array<mixed>|string> $routes Verb => route map
     * @param array<string,bool> $set Map being populated (by reference)
     */
    protected function addAllowedFromRoutes(array $routes, array &$set): void
    {
        foreach ($routes as $verb => $_route) {
            $set[$verb] = true;
        }
        if (isset($routes[HttpMethodEnum::GET->value])) {
            $set[HttpMethodEnum::HEAD->value] = true;
        }
    }

    /* ──────────────────── canonical host (mirrors RouterKernel rules) ──────────────────── */

    /**
     * Normalise a host name for internal route storage/lookup.
     *
     * Behaviour:
     *  - Null/empty/'*' maps to literal '*' (wildcard host).
     *  - Trailing dots are removed and the host is lower-cased.
     *  - If idn_to_ascii is available, internationalised names are converted to ASCII
     *    (skipping punycode names that already contain 'xn--').
     *  - Hosts containing control characters or non-ASCII bytes are rejected.
     *
     * @param string|null $raw Raw Host header value or route host specification.
     * @return string Normalised ASCII host (or '*' for wildcard).
     *
     * @throws \InvalidArgumentException When the host is illegal, invalid IDN, or contains non-ASCII bytes.
     */
    protected function canonicalRouteHost(?string $raw): string
    {
        if ($raw === null || $raw === '' || $raw === '*') {
            return '*';
        }
        $host = \rtrim(\strtolower($raw), '.');

        if (\preg_match('/[\x00-\x20]/', $host)) {
            throw new \InvalidArgumentException("Illegal host name: {$raw}");
        }
        if (\function_exists('idn_to_ascii') && !\str_contains($host, 'xn--')) {
            $ascii = \idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException("Invalid IDN host name: {$raw}");
            }
            $host = $ascii;
        }
        if (!\preg_match('/^[\x21-\x7E]+$/', $host)) {
            throw new \InvalidArgumentException("Host contains non-ASCII bytes: {$raw}");
        }

        return $host;
    }

    /**
     * @param array<string,mixed> $node
     */
    protected function ensureNode(array &$node): void
    {
        if (!\is_array($node[self::K_CHILDREN] ?? null)) {
            $node[self::K_CHILDREN] = [];
        }
        if (!array_key_exists(self::K_PARAM, $node) || (!\is_array($node[self::K_PARAM]) && $node[self::K_PARAM] !== null)) {
            $node[self::K_PARAM] = null;
        }
        if (!\is_array($node[self::K_ROUTES] ?? null)) {
            $node[self::K_ROUTES] = [];
        }
    }

    /**
     * Split a path into segments (without leading/trailing slashes).
     *
     * Returns an empty array for root '/'.
     *
     * @param string $p Raw path (e.g. '/users/{id}')
     * @return list<string> Array of path segments
     */
    protected function explodePath(string $p): array
    {
        $t = \trim($p, '/');

        return $t === '' ? [] : \explode('/', $t);
    }

    /* ──────────────────── export helpers ──────────────────── */

    /**
     * Export an array into a PHP source-like formatted string (used for caches).
     *
     * Produces a readable representation intended for embedding into generated
     * PHP cache files.
     *
     * @param array<mixed,mixed> $a Array to export
     * @param int $depth Current indentation depth
     * @return string PHP-like representation
     */
    protected function exportArray(array $a, int $depth = 0): string
    {
        $indent = \str_repeat('    ', $depth);
        $out = "[\n";
        foreach ($a as $k => $v) {
            $out .= $indent . '    ' . \var_export($k, true) . ' => ';
            $out .= \is_array($v) ? $this->exportArray($v, $depth + 1) : $this->exportValue($v, $depth + 1);
            $out .= ",\n";
        }

        return $indent . \rtrim($out, ",\n") . "\n" . $indent . ']';
    }

    /**
     * Produce a lazy cache payload for a CompiledRoute.
     *
     * Scalar routes remain arrays until matched. Routes containing runtime
     * objects or closures retain the serializer fallback.
     */
    protected function exportRoute(CompiledRoute $r): string
    {
        return \var_export(MatcherCachePayloadNormalizer::normalize($r), true);
    }

    /**
     * Export a single value into a PHP source-like string.
     *
     * CompiledRoute instances are handled specially so scalar routes remain
     * native arrays while runtime values use Webrick's versioned route envelope.
     *
     * @param mixed $v Value to export
     * @param int $depth Indentation depth (unused for non-array values)
     * @return string PHP-like representation
     */
    protected function exportValue(mixed $v, int $depth): string
    {
        return $v instanceof CompiledRoute
            ? $this->exportRoute($v)
            : (\is_array($v) ? $this->exportArray($v, $depth) : \var_export($v, true));
    }

    /**
     * Build a lightweight file stamp used for cache staleness checks.
     *
     * Format: "<mtime>:<size>" where both parts are decimal integers.
     * Returns null when the file does not exist or metadata cannot be read.
     *
     * @param string $file Absolute/relative file path.
     * @return string|null File stamp or null when unavailable.
     */
    protected function fileStamp(string $file): ?string
    {
        \clearstatcache(true, $file);
        $mtime = \filemtime($file);
        if ($mtime === false) {
            return null;
        }

        $size = \filesize($file);
        if ($size === false) {
            $size = 0;
        }

        return $mtime . ':' . $size;
    }

    /**
     * Determine whether a trie node is empty (no children, no param, no routes).
     *
     * @param array<string,mixed> $n Node to inspect
     * @return bool True when node contains no useful entries
     */
    protected function isEmptyTrieNode(array $n): bool
    {
        $this->ensureNode($n);

        return $n[self::K_CHILDREN] === []
            && $n[self::K_PARAM] === null
            && $n[self::K_ROUTES] === [];
    }

    /* ──────────────────── trie helpers ──────────────────── */

    /**
     * Create a new empty trie node with standard slot keys.
     *
     * @return array<string,mixed> New node structure
     */
    protected function newNode(): array
    {
        return [self::K_CHILDREN => [], self::K_PARAM => null, self::K_ROUTES => []];
    }

    /**
     * @param array<mixed,mixed> $node
     * @return array<string,mixed>
     */
    protected function normalizeNodeArray(array $node): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            if (\is_string($k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /* ──────────────────── verb selection + allowed-set helpers ──────────────────── */

    /**
     * Select the appropriate CompiledRoute for the requested HTTP verb.
     *
     * Rules:
     *  - OPTIONS returns the first available route in the bucket (if any).
     *  - Exact verb match returns that route.
     *  - HEAD falls back to GET when a GET route exists.
     *
     * @param array<string,CompiledRoute|array<mixed>|string> $buckets Map of verb => compiled or serialized route
     * @param string $verb Uppercased HTTP verb to resolve (e.g. 'GET')
     * @return CompiledRoute|null Matching compiled route or null when none applicable
     */
    protected function pickVerbRoute(array $buckets, string $verb): ?CompiledRoute
    {
        if ($verb === HttpMethodEnum::OPTIONS->value && $buckets) {
            $first = \reset($buckets);

            return $this->materializeRoute($first);
        }
        if (isset($buckets[$verb])) {
            return $this->materializeRoute($buckets[$verb]);
        }
        if ($verb === HttpMethodEnum::HEAD->value && isset($buckets[HttpMethodEnum::GET->value])) {
            return $this->materializeRoute($buckets[HttpMethodEnum::GET->value]);
        }

        return null;
    }

    /**
     * Whether opcache compile warm-up calls are safe in the current runtime.
     *
     * Avoids noisy runtime notices when OPcache extension exists but is disabled
     * for the current SAPI (common in CLI test runs).
     */
    protected function shouldWarmOpcache(): bool
    {
        return matcher_should_warm_opcache();
    }

    /**
     * Insert a compiled route into a trie rooted at $root for the given verb.
     *
     * Traverses/creates nodes for each segment and stores the route under the
     * final node's routes map keyed by verb. Duplicate dynamic route (same verb
     * at same path) causes a LogicException.
     *
     * @param array<string,mixed> $root Trie root (by reference)
     * @param CompiledRoute $r Compiled route to insert
     * @param string $verb HTTP verb (uppercased)
     *
     * @throws \LogicException On duplicate dynamic route insertion.
     */
    protected function trieInsert(array &$root, CompiledRoute $r, string $verb): void
    {
        $this->ensureNode($root);
        $node = &$root;
        foreach ($r->getSegments() as $seg) {
            $node = &$this->trieInsertSegment($node, $seg);
        }
        $this->ensureNode($node);
        $routes = \is_array($node[self::K_ROUTES]) ? $node[self::K_ROUTES] : [];
        if (isset($routes[$verb])) {
            throw new \LogicException("Duplicate dynamic route {$verb} {$r->getPath()}");
        }
        $routes[$verb] = $r;
        $node[self::K_ROUTES] = $routes;
    }

    /**
     * Recursive trie walker that attempts to match a sequence of path segments.
     *
     * This method tries literal child first, then parameter branch (regex or
     * callable). When the end of segments is reached it attempts verb selection
     * via pickVerbRoute. It also accumulates allowed methods in $allowedSet
     * when a node contains routes but none matches the requested verb.
     *
     * @param array<string,mixed> $node Current trie node being examined
     * @param list<string> $seg Array of path segments being matched
     * @param int $i Current index into $seg
     * @param string $verb Requested HTTP verb (uppercased)
     * @param array<string,string> $params Route parameters collected so far (by reference)
     * @param array<string,bool> $allowedSet Allowed methods accumulator (by reference)
     * @param array{0:CompiledRoute,1:array<string,string>}|null $hit Out param set to [route, params] on success
     * @return bool True when a match was found and $hit populated
     */
    protected function trieWalkNode(
        array $node,
        array $seg,
        int $i,
        string $verb,
        array &$params,
        array &$allowedSet,
        ?array &$hit,
    ): bool {
        $this->ensureNode($node);

        if ($i === \count($seg)) {
            return $this->trieWalkTerminal($node, $verb, $params, $allowedSet, $hit);
        }

        $piece = $seg[$i];

        if ($this->tryTrieLiteralBranch($node, $piece, $seg, $i, $verb, $params, $allowedSet, $hit)) {
            return true;
        }

        return $this->tryTrieParamBranch($node, $piece, $seg, $i, $verb, $params, $allowedSet, $hit);
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function &trieInsertSegment(array &$node, mixed $segment): array
    {
        if (\is_array($segment) && ($segment['type'] ?? null) === 'lit' && \is_string($segment['val'] ?? null)) {
            return $this->trieLiteralChild($node, $segment['val']);
        }

        if (!\is_array($segment) || !\is_string($segment['name'] ?? null)) {
            throw new \LogicException('Invalid dynamic segment spec.');
        }

        $call = $segment['call'] ?? null;
        $callable = \is_string($call) && \is_callable($call) ? $call : null;
        /** @var TrieParamSpec $paramSpec */
        $paramSpec = [
            'name' => $segment['name'],
            'regex' => \is_string($segment['regex'] ?? null) ? $segment['regex'] : null,
            'call' => $callable,
        ];

        return $this->trieParamChild($node, $paramSpec);
    }

    /**
     * @param array<string,array<string,mixed>> $children
     * @return array<string,mixed>
     */
    private function &childNodeRef(array &$children, string $seg): array
    {
        return $children[$seg];
    }

    /**
     * @return array<string,CompiledRoute|array<mixed>|string>
     */
    private function compiledRouteMap(mixed $routes): array
    {
        return matcher_normalize_compiled_route_map($routes);
    }

    private function materializeRoute(mixed $route): ?CompiledRoute
    {
        if ($route instanceof CompiledRoute) {
            return $route;
        }
        if (\is_array($route)) {
            $index = $route[10] ?? null;
            if (!\is_int($index)) {
                return null;
            }
            $key = 'payload:' . $index;

            return $this->materializedRoutes[$key] ??= CompiledRoute::fromCachePayload($route);
        }
        if (!\is_string($route)) {
            return null;
        }
        if (isset($this->materializedRoutes[$route])) {
            return $this->materializedRoutes[$route];
        }

        $materialized = matcher_unserialize_cached_route($route);

        return $this->materializedRoutes[$route] = $materialized;
    }

    /* ──────────────────── helpers (rule + matching) ──────────────────── */

    /**
     * Return which rule key ('regex' or 'call') the parameter spec contains.
     *
     * @param array{name:string,regex?:string|null,call?:string|null} $spec Parameter specification
     * @return string Either 'regex' or 'call'
     *
     * @throws \LogicException When neither regex nor call is present.
     */
    private function paramRuleKey(array $spec): string
    {
        if (isset($spec['regex'])) {
            return 'regex';
        }
        if (isset($spec['call'])) {
            return 'call';
        }

        throw new \LogicException('Param spec missing both regex and call.');
    }

    /**
     * Check whether a path piece satisfies a parameter node's constraint.
     *
     * - If the node has a 'regex' key the piece is tested with preg_match.
     * - If the node has a 'call' key the callable is invoked with the piece and
     *   its boolean result determines acceptance.
     *
     * @param array{name:string,regex?:string|null,call?:callable-string|null} $p Parameter node info
     * @param string $piece Single path segment text to test
     * @return bool True when the piece matches the parameter rule
     */
    private function pieceMatches(array $p, string $piece): bool
    {
        if (!empty($p['regex'])) {
            return \preg_match($p['regex'], $piece) === 1;
        }
        if (!empty($p['call'])) {
            /** @var callable-string $fn */
            $fn = $p['call'];

            // Direct invocation is faster than call_user_func for callable-strings.
            return (bool) $fn($piece);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,string> $params
     * @param array<string,bool> $allowedSet
     * @param array{0:CompiledRoute,1:array<string,string>}|null $hit
     */
    private function trieWalkTerminal(
        array $node,
        string $verb,
        array $params,
        array &$allowedSet,
        ?array &$hit,
    ): bool {
        $routes = $this->compiledRouteMap($node[self::K_ROUTES]);
        if ($r = $this->pickVerbRoute($routes, $verb)) {
            $hit = [$r, $params];

            return true;
        }
        if ($routes !== []) {
            $this->addAllowedFromRoutes($routes, $allowedSet);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $node
     * @param list<string> $seg
     * @param array<string,string> $params
     * @param array<string,bool> $allowedSet
     * @param array{0:CompiledRoute,1:array<string,string>}|null $hit
     */
    private function tryTrieLiteralBranch(
        array $node,
        string $piece,
        array $seg,
        int $i,
        string $verb,
        array &$params,
        array &$allowedSet,
        ?array &$hit,
    ): bool {
        $children = \is_array($node[self::K_CHILDREN] ?? null) ? $node[self::K_CHILDREN] : [];
        if (!isset($children[$piece]) || !\is_array($children[$piece])) {
            return false;
        }
        $child = $this->normalizeNodeArray($children[$piece]);
        $this->ensureNode($child);

        return $this->trieWalkNode($child, $seg, $i + 1, $verb, $params, $allowedSet, $hit);
    }

    /**
     * @param array<string,mixed> $node
     * @param list<string> $seg
     * @param array<string,string> $params
     * @param array<string,bool> $allowedSet
     * @param array{0:CompiledRoute,1:array<string,string>}|null $hit
     */
    private function tryTrieParamBranch(
        array $node,
        string $piece,
        array $seg,
        int $i,
        string $verb,
        array &$params,
        array &$allowedSet,
        ?array &$hit,
    ): bool {
        $p = $node[self::K_PARAM] ?? null;
        if (!\is_array($p) || !\is_string($p['name'] ?? null)) {
            return false;
        }

        $call = $p['call'] ?? null;
        $callable = \is_string($call) && \is_callable($call) ? $call : null;
        /** @var TrieParamSpec $spec */
        $spec = [
            'name' => $p['name'],
            'regex' => \is_string($p['regex'] ?? null) ? $p['regex'] : null,
            'call' => $callable,
        ];
        if (!$this->pieceMatches($spec, $piece)) {
            return false;
        }

        $params[$spec['name']] = $piece;
        $next = \is_array($p['node'] ?? null) ? $this->normalizeNodeArray($p['node']) : $this->newNode();
        $this->ensureNode($next);
        $ok = $this->trieWalkNode($next, $seg, $i + 1, $verb, $params, $allowedSet, $hit);
        unset($params[$spec['name']]);

        return $ok;
    }
}
