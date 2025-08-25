<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Closure;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Common utilities shared by matchers.
 * Holds structure keys, verb resolution, host canonicalization,
 * trie helpers, export helpers, and allowed-set aggregation.
 */
abstract class AbstractMatcher
{
    /* shared node keys */
    protected const K_STATIC   = 'static';
    protected const K_TRIE     = 'trie';
    protected const K_CHILDREN = 'children';
    protected const K_PARAM    = 'param';   // ['name'=>..., 'regex'=>?string, 'call'=>?callable-string, 'node'=>array]
    protected const K_ROUTES   = 'routes';

    /** Optional: verify shard/cache hash on load (dev/CI) */
    protected bool $verifyCacheOnLoad = false;

    public function verifyCacheOnLoad(bool $enable = true): static
    {
        $this->verifyCacheOnLoad = $enable;
        return $this;
    }

    /*──────────── canonical host (matches RouterKernel rules) ───────────*/
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

    /*──────────── verb selection + allowed-set helpers ───────────*/
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

    /** No array_merge/unique; just set bits. */
    protected function addAllowedFromMap(array $map, array &$set): void
    {
        foreach ($map as $verb => $_route) {
            $set[$verb] = true;
        }
        if (isset($map['GET'])) {
            $set['HEAD'] = true;
        }
    }

    /** No array_merge/unique; just set bits. */
    protected function addAllowedFromRoutes(array $routes, array &$set): void
    {
        foreach ($routes as $verb => $_route) {
            $set[$verb] = true;
        }
        if (isset($routes['GET'])) {
            $set['HEAD'] = true;
        }
    }

    /*──────────── trie helpers ───────────*/
    protected function newNode(): array
    {
        return [self::K_CHILDREN => [], self::K_PARAM => null, self::K_ROUTES => []];
    }

    protected function &trieLiteralChild(array &$node, string $seg): array
    {
        $node[self::K_CHILDREN][$seg] ??= $this->newNode();
        return $node[self::K_CHILDREN][$seg];
    }

    /**
     * Accepts var segment specs that may contain **regex** OR **call**.
     * Ensures same-depth placeholders are identical (name + rule).
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
            'name'  => $spec['name'],
            'regex' => $spec['regex'] ?? null,
            'call'  => $spec['call']  ?? null,
            'node'  => $this->newNode(),
        ];
        return $node[self::K_PARAM]['node'];
    }

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

    protected function explodePath(string $p): array
    {
        $t = \trim($p, '/');
        return $t === '' ? [] : \explode('/', $t);
    }

    /** Shared trie walker used by both matchers. */
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

        // literal branch
        if (isset($node[self::K_CHILDREN][$piece]) &&
            $this->trieWalkNode($node[self::K_CHILDREN][$piece], $seg, $i + 1, $verb, $params, $allowedSet, $hit)) {
            return true;
        }

        // param branch (regex OR callable)
        $p = $node[self::K_PARAM];
        if ($p && $this->pieceMatches($p, $piece)) {
            $params[$p['name']] = $piece;        // push
            $ok = $this->trieWalkNode($p['node'], $seg, $i + 1, $verb, $params, $allowedSet, $hit);
            unset($params[$p['name']]);          // pop
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    protected function isEmptyTrieNode(array $n): bool
    {
        return ($n[self::K_CHILDREN] ?? []) === []
            && ($n[self::K_PARAM] ?? null) === null
            && ($n[self::K_ROUTES] ?? []) === [];
    }

    /*──────────── helpers (rule + matching) ───────────*/

    /** @param array{name:string,regex?:string,call?:string} $spec */
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

    /** @param array{name:string,regex?:string|null,call?:string|null} $p */
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

    /*──────────── export helpers ───────────*/
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

    protected function exportValue(mixed $v, int $depth): string
    {
        return $v instanceof CompiledRoute
            ? $this->exportRoute($v)
            : (\is_array($v) ? $this->exportArray($v, $depth) : \var_export($v, true));
    }

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

    protected function handlerHasClosure(callable|array|string $h): bool
    {
        return $h instanceof Closure
            || (\is_array($h) && (($h[0] ?? null) instanceof Closure || ($h[1] ?? null) instanceof Closure));
    }

    /* optional hook for kernels; children override if applicable */
    public function canBootFromCache(): bool
    {
        return false;
    }
}
