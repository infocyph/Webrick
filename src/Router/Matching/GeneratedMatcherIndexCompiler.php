<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

/** Converts canonical matcher indexes into compact generated-matcher tables. */
final class GeneratedMatcherIndexCompiler
{
    /**
     * @param array<string,array{static:array<string,array<string,mixed>>,dynamic:array<int,array<string,array<string,array{segments:list<array<string,mixed>>,verbs:array<string,mixed>}>>>}> $indexes
     * @return array{0:array<int,mixed>,1:array<string,array{static:array<string,array<string,int>>,dynamic:array<int,array<string,array<string,array{segments:list<array<string,mixed>>,verbs:array<string,int>}>>>}>}
     */
    public function compile(array $indexes): array
    {
        $routeIndexer = new GeneratedMatcherRouteIndexer();
        $hosts = [];
        foreach ($indexes as $host => $index) {
            $hosts[$host] = $this->compileHost($index, $routeIndexer);
        }

        return [$routeIndexer->payloads(), $hosts];
    }

    /**
     * @param array<int,array<string,array<string,array{segments:list<array<string,mixed>>,verbs:array<string,mixed>}>>> $routes
     * @return array<int,array<string,array<string,array{segments:list<array<string,mixed>>,verbs:array<string,int>}>>>
     */
    private function compileDynamic(array $routes, GeneratedMatcherRouteIndexer $routeIndexer): array
    {
        $compiled = [];
        foreach ($routes as $count => $prefixes) {
            foreach ($prefixes as $prefix => $entries) {
                foreach ($entries as $path => $entry) {
                    $compiled[$count][$prefix][$path] = [
                        'segments' => $entry['segments'],
                        'verbs' => $this->compileVerbs($entry['verbs'], $routeIndexer),
                    ];
                }
            }
        }

        return $compiled;
    }

    /**
     * @param array{static:array<string,array<string,mixed>>,dynamic:array<int,array<string,array<string,array{segments:list<array<string,mixed>>,verbs:array<string,mixed>}>>>} $index
     * @return array{static:array<string,array<string,int>>,dynamic:array<int,array<string,array<string,array{segments:list<array<string,mixed>>,verbs:array<string,int>}>>>}
     */
    private function compileHost(array $index, GeneratedMatcherRouteIndexer $routeIndexer): array
    {
        return [
            'static' => $this->compileStatic($index['static'], $routeIndexer),
            'dynamic' => $this->compileDynamic($index['dynamic'], $routeIndexer),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $routes
     * @return array<string,array<string,int>>
     */
    private function compileStatic(array $routes, GeneratedMatcherRouteIndexer $routeIndexer): array
    {
        $compiled = [];
        foreach ($routes as $path => $verbs) {
            foreach ($verbs as $verb => $route) {
                $compiled[$path][$verb] = $routeIndexer->index($route);
            }
        }

        return $compiled;
    }

    /**
     * @param array<string,mixed> $verbs
     * @return array<string,int>
     */
    private function compileVerbs(array $verbs, GeneratedMatcherRouteIndexer $routeIndexer): array
    {
        $compiled = [];
        foreach ($verbs as $verb => $route) {
            $compiled[$verb] = $routeIndexer->index($route);
        }

        return $compiled;
    }
}
