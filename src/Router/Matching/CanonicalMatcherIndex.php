<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use UnexpectedValueException;

/**
 * Canonical routing IR shared by matcher backends.
 *
 * Static routes are exact path maps. Dynamic routes are grouped by segment
 * count and first-literal prefix so runtime matching never needs a recursive
 * trie walk merely to discover plausible candidates.
 *
 * @phpstan-type RouteValue CompiledRoute|array<mixed>|string
 * @phpstan-type VerbMap array<string,RouteValue>
 * @phpstan-type SegmentSpec array{type:'lit',val:string}|array{type:'var',name:string,regex:string}|array{type:'var',name:string,call:callable-string}
 * @phpstan-type DynamicEntry array{segments:list<SegmentSpec>,verbs:VerbMap}
 * @phpstan-type DynamicBuckets array<int,array<string,array<string,DynamicEntry>>>
 * @phpstan-type HostIndex array{static:array<string,VerbMap>,dynamic:DynamicBuckets}
 * @phpstan-type HostMap array<string,HostIndex>
 */
final class CanonicalMatcherIndex
{
    /** @var HostMap */
    private array $hosts = [];

    public function add(string $host, CompiledRoute $route): void
    {
        $verb = HttpMethodEnum::normalize($route->getMethod());
        $path = $route->getPath();
        $this->hosts[$host] ??= ['static' => [], 'dynamic' => []];

        if (!$route->isDynamic()) {
            if (isset($this->hosts[$host]['static'][$path][$verb])) {
                throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
            }
            $this->hosts[$host]['static'][$path][$verb] = $route;

            return;
        }

        $rawSegments = $route->getSegments();
        $segments = $this->normalizeSegments($rawSegments, count($rawSegments));
        $count = count($segments);
        $prefix = self::prefixForSegments($segments);
        if (isset($this->hosts[$host]['dynamic'][$count][$prefix][$path]['verbs'][$verb])) {
            throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
        }

        $this->hosts[$host]['dynamic'][$count][$prefix][$path] ??= [
            'segments' => $segments,
            'verbs' => [],
        ];
        $this->hosts[$host]['dynamic'][$count][$prefix][$path]['verbs'][$verb] = $route;
    }

    /** @return HostMap */
    public function hosts(): array
    {
        return $this->hosts;
    }

    public function isEmpty(): bool
    {
        return $this->hosts === [];
    }

    public static function prefixForPath(string $path): string
    {
        if ($path === '/' || $path === '') {
            return '';
        }

        $trimmed = $path[0] === '/' ? substr($path, 1) : $path;
        $pos = strpos($trimmed, '/');

        return $pos === false ? $trimmed : substr($trimmed, 0, $pos);
    }

    /** @param list<array<string,mixed>> $segments */
    public static function prefixForSegments(array $segments): string
    {
        $first = $segments[0] ?? null;

        return is_array($first)
            && ($first['type'] ?? null) === 'lit'
            && is_string($first['val'] ?? null)
            ? $first['val']
            : '*';
    }

    public function replaceFromCache(mixed $raw): void
    {
        if (!is_array($raw)) {
            throw new UnexpectedValueException('Canonical matcher index must be an array.');
        }

        $hosts = [];
        foreach ($raw as $host => $value) {
            if (!is_string($host) || !is_array($value)) {
                throw new UnexpectedValueException('Invalid canonical matcher host index.');
            }
            $hosts[$host] = [
                'static' => $this->normalizeStatic($value['static'] ?? null),
                'dynamic' => $this->normalizeDynamic($value['dynamic'] ?? null),
            ];
        }

        $this->hosts = $hosts;
    }

    /** @return array<int,array<string,array<string,array{segments:list<array<string,mixed>>,verbs:array<string,CompiledRoute|array<mixed>|string>}>>> */
    private function normalizeDynamic(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $dynamic = [];
        foreach ($raw as $count => $prefixes) {
            if (!is_int($count) || !is_array($prefixes)) {
                throw new UnexpectedValueException('Invalid canonical dynamic segment-count bucket.');
            }
            foreach ($prefixes as $prefix => $entries) {
                if (!is_string($prefix) || !is_array($entries)) {
                    throw new UnexpectedValueException('Invalid canonical dynamic prefix bucket.');
                }
                foreach ($entries as $path => $entry) {
                    if (!is_string($path) || !is_array($entry)) {
                        throw new UnexpectedValueException('Invalid canonical dynamic route entry.');
                    }
                    $segments = $this->normalizeSegments($entry['segments'] ?? null, $count);
                    $verbs = matcher_normalize_compiled_route_map($entry['verbs'] ?? null);
                    if ($verbs === []) {
                        throw new UnexpectedValueException('Canonical dynamic route requires at least one verb.');
                    }
                    $dynamic[$count][$prefix][$path] = [
                        'segments' => $segments,
                        'verbs' => $verbs,
                    ];
                }
            }
        }

        return $dynamic;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeSegments(mixed $raw, int $expectedCount): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) !== $expectedCount) {
            throw new UnexpectedValueException('Invalid canonical matcher segment list.');
        }

        $segments = [];
        foreach ($raw as $segment) {
            if (!is_array($segment) || !is_string($segment['type'] ?? null)) {
                throw new UnexpectedValueException('Invalid canonical matcher segment.');
            }
            if ($segment['type'] === 'lit') {
                if (!is_string($segment['val'] ?? null)) {
                    throw new UnexpectedValueException('Invalid literal matcher segment.');
                }
                $segments[] = ['type' => 'lit', 'val' => $segment['val']];

                continue;
            }
            if ($segment['type'] !== 'var' || !is_string($segment['name'] ?? null)) {
                throw new UnexpectedValueException('Invalid variable matcher segment.');
            }
            if (is_string($segment['regex'] ?? null)) {
                $segments[] = ['type' => 'var', 'name' => $segment['name'], 'regex' => $segment['regex']];

                continue;
            }
            $call = $segment['call'] ?? null;
            if (!is_string($call) || !is_callable($call)) {
                throw new UnexpectedValueException('Callable route constraint is unavailable at matcher build/load.');
            }
            /** @var callable-string $call */
            $segments[] = ['type' => 'var', 'name' => $segment['name'], 'call' => $call];
        }

        return $segments;
    }

    /** @return array<string,array<string,CompiledRoute|array<mixed>|string>> */
    private function normalizeStatic(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $static = [];
        foreach ($raw as $path => $verbs) {
            if (!is_string($path)) {
                throw new UnexpectedValueException('Invalid canonical static route path.');
            }
            $map = matcher_normalize_compiled_route_map($verbs);
            if ($map === []) {
                throw new UnexpectedValueException('Canonical static route requires at least one verb.');
            }
            $static[$path] = $map;
        }

        return $static;
    }
}
