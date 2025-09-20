<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Closure;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\Webrick\Router\Route\CompiledRoute;

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
 * @package Infocyph\Webrick\Router\Matching
 */
abstract class AbstractMatcher
{
    /* Shared node keys used in trie structures */
    protected const K_STATIC = 'static';
    protected const K_TRIE = 'trie';
    protected const K_CHILDREN = 'children';
    protected const K_PARAM = 'param';
    protected const K_ROUTES = 'routes';

    /* Header / cache blob keys */
    protected const H_HASH = '_hash';
    protected const H_DATA = '_data';
    protected const H_TS = '_ts';
    protected const F_ALIASES = '__aliases.php';
    protected const H_ALIAS = '_alias';

    /**
     * When true the matcher will perform an integrity verification when loading
     * caches. Default false.
     *
     * @var bool
     */
    protected bool $verifyCacheOnLoad = false;

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

    /*──────────────────── canonical host (mirrors RouterKernel rules) ────────────────────*/

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
            $ascii = @\idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
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

    /*──────────────────── verb selection + allowed-set helpers ────────────────────*/

    /**
     * Select the appropriate CompiledRoute for the requested HTTP verb.
     *
     * Rules:
     *  - OPTIONS returns the first available route in the bucket (if any).
     *  - Exact verb match returns that route.
     *  - HEAD falls back to GET when a GET route exists.
     *
     * @param array<string,CompiledRoute> $buckets Map of verb => CompiledRoute
     * @param string $verb Uppercased HTTP verb to resolve (e.g. 'GET')
     * @return CompiledRoute|null Matching compiled route or null when none applicable
     */
    protected function pickVerbRoute(array $buckets, string $verb): ?CompiledRoute
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

    /**
     * Populate an allowed-set map from a verb => route map.
     *
     * Adds HEAD automatically when GET is present. Uses bit-set-style map where
     * keys are verbs and values are true.
     *
     * @param array<string,mixed> $map Verb => route-like map (values not inspected)
     * @param array<string,bool>  $set Map being populated (by reference)
     * @return void
     */
    protected function addAllowedFromMap(array $map, array &$set): void
    {
        foreach ($map as $verb => $_route) {
            $set[$verb] = true;
        }
        if (isset($map['GET'])) {
            $set['HEAD'] = true;
        }
    }

    /**
     * Populate an allowed-set map from an array of CompiledRoute entries.
     *
     * Equivalent to addAllowedFromMap but provided for semantic clarity when the
     * source is an array of route instances.
     *
     * @param array<string,CompiledRoute> $routes Verb => CompiledRoute map
     * @param array<string,bool>          $set    Map being populated (by reference)
     * @return void
     */
    protected function addAllowedFromRoutes(array $routes, array &$set): void
    {
        foreach ($routes as $verb => $_route) {
            $set[$verb] = true;
        }
        if (isset($routes['GET'])) {
            $set['HEAD'] = true;
        }
    }

    /*──────────────────── trie helpers ────────────────────*/

    /**
     * Create a new empty trie node with standard slot keys.
     *
     * @return array{children:array,param:?array,routes:array} New node structure
     */
    protected function newNode(): array
    {
        return [self::K_CHILDREN => [], self::K_PARAM => null, self::K_ROUTES => []];
    }

    /**
     * Ensure and return a literal child node for the given segment.
     *
     * This returns a reference to the child node so callers can mutate it
     * directly when inserting further segments.
     *
     * @param array $node Parent node (by reference)
     * @param string $seg Literal segment value
     * @return array Child node (reference)
     */
    protected function &trieLiteralChild(array &$node, string $seg): array
    {
        $node[self::K_CHILDREN][$seg] ??= $this->newNode();
        return $node[self::K_CHILDREN][$seg];
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
     * @param array $node Parent node (by reference)
     * @param array{name:string,regex?:string,call?:callable-string} $spec Parameter spec
     * @return array Child node (reference)
     *
     * @throws \LogicException When placeholders conflict at the same trie depth.
     */
    protected function &trieParamChild(array &$node, array $spec): array
    {
        $ruleKey = $this->paramRuleKey($spec); // 'regex' or 'call'

        if ($node[self::K_PARAM] !== null) {
            $cur = $node[self::K_PARAM];
            if (
                $cur['name'] !== $spec['name']
                || ($cur[$ruleKey] ?? null) !== ($spec[$ruleKey] ?? null)
            ) {
                throw new \LogicException("Conflicting placeholders at same depth");
            }
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
     * @return void
     */
    protected function trieInsert(array &$root, CompiledRoute $r, string $verb): void
    {
        $node = &$root;
        foreach ($r->getSegments() as $seg) {
            if ($seg['type'] === 'lit') {
                $node = &$this->trieLiteralChild($node, $seg['val']);
            } else {
                // var segment: may have 'regex' or 'call'
                $node = &$this->trieParamChild($node, $seg);
            }
        }
        if (isset($node[self::K_ROUTES][$verb])) {
            throw new \LogicException("Duplicate dynamic route {$verb} {$r->getPath()}");
        }
        $node[self::K_ROUTES][$verb] = $r;
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

    /**
     * Recursive trie walker that attempts to match a sequence of path segments.
     *
     * This method tries literal child first, then parameter branch (regex or
     * callable). When the end of segments is reached it attempts verb selection
     * via pickVerbRoute. It also accumulates allowed methods in $allowedSet
     * when a node contains routes but none matches the requested verb.
     *
     * @param array $node Current trie node being examined
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
        if ($i === \count($seg)) {
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

        $piece = $seg[$i];

        // literal branch — prefer exact literal matches first
        if (isset($node[self::K_CHILDREN][$piece]) &&
            $this->trieWalkNode($node[self::K_CHILDREN][$piece], $seg, $i + 1, $verb, $params, $allowedSet, $hit)) {
            return true;
        }

        // parameter branch (regex OR callable)
        $p = $node[self::K_PARAM];
        if ($p && $this->pieceMatches($p, $piece)) {
            // push param value
            $params[$p['name']] = $piece;
            $ok = $this->trieWalkNode($p['node'], $seg, $i + 1, $verb, $params, $allowedSet, $hit);
            // pop param value
            unset($params[$p['name']]);
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a trie node is empty (no children, no param, no routes).
     *
     * @param array $n Node to inspect
     * @return bool True when node contains no useful entries
     */
    protected function isEmptyTrieNode(array $n): bool
    {
        return ($n[self::K_CHILDREN] ?? []) === []
            && ($n[self::K_PARAM] ?? null) === null
            && ($n[self::K_ROUTES] ?? []) === [];
    }

    /*──────────────────── helpers (rule + matching) ────────────────────*/

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
            // direct call (expects a string argument)
            return (bool)\call_user_func($fn, $piece);
        }
        return false;
    }

    /*──────────────────── export helpers ────────────────────*/

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
        return $indent . \rtrim($out, ",\n") . "\n" . $indent . "]";
    }

    /**
     * Export a single value into a PHP source-like string.
     *
     * CompiledRoute instances are handled specially to avoid serialising closures
     * into cache blobs; routes with closures are serialized via ValueSerializer.
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
     * Produce PHP source to recreate a CompiledRoute instance.
     *
     * When the route's handler is a Closure the ValueSerializer is used to
     * produce a safe serialised form; otherwise a direct constructor expression
     * is emitted so consumers may instantiate or memoize the class-string.
     *
     * @param CompiledRoute $r Compiled route to export
     * @return string PHP expression that reconstructs the route
     */
    protected function exportRoute(CompiledRoute $r): string
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
        return '\\' . ValueSerializer::class
            . '::unserialize(' . \var_export(ValueSerializer::serialize($r), true) . ')';
    }

    /**
     * Detect whether the given handler contains a Closure element.
     *
     * Returns true when:
     *  - handler is a Closure
     *  - handler is an array and either element 0 or 1 is a Closure instance
     *
     * @param callable|array|string $h Candidate handler
     * @return bool True when handler contains a Closure
     */
    protected function handlerHasClosure(callable|array|string $h): bool
    {
        return $h instanceof Closure
            || (\is_array($h) && (($h[0] ?? null) instanceof Closure || ($h[1] ?? null) instanceof Closure));
    }

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
}
