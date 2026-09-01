<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Validates the central-route-table production matcher IR. */
final class CompactMatcherIndexValidator
{
    private function __construct() {}

    /** @return array<string,array<string,mixed>> */
    public static function validateHosts(mixed $raw, bool $validateRegex = false): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher hosts must be an array.');
        }

        $hosts = [];
        foreach ($raw as $host => $group) {
            if (!is_string($host) || $host === '') {
                throw new \UnexpectedValueException('Compact matcher host key is invalid.');
            }
            $hosts[$host] = self::validateGroup($group, $validateRegex);
        }

        return $hosts;
    }

    /** @return array<string,mixed> */
    public static function validateGroup(mixed $raw, bool $validateRegex = false): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher group must be an array.');
        }

        $routes = self::validateRoutes($raw['routes'] ?? null);

        return [
            'routes' => $routes,
            'static' => self::validateStatic($raw['static'] ?? null, $routes),
            'static_allowed' => self::validateStaticAllowed($raw['static_allowed'] ?? null),
            'dynamic' => self::validateDynamic($raw['dynamic'] ?? null, $routes, $validateRegex),
            'dynamic_allowed' => self::validateDynamicAllowed($raw['dynamic_allowed'] ?? null, $validateRegex),
        ];
    }

    /** @return array<int,mixed> */
    private static function validateRoutes(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher route table must be an array.');
        }

        $routes = [];
        foreach ($raw as $id => $route) {
            if (!is_int($id) || $id < 0 || self::routeIndex($route) !== $id) {
                throw new \UnexpectedValueException('Compact matcher route table entry is invalid.');
            }
            $routes[$id] = $route;
        }

        return $routes;
    }

    /**
     * @param array<int,mixed> $routes
     * @return array<string,array<string,int>>
     */
    private static function validateStatic(mixed $raw, array $routes): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher static map must be an array.');
        }

        $static = [];
        foreach ($raw as $method => $paths) {
            if (!is_string($method) || $method === '' || !is_array($paths)) {
                throw new \UnexpectedValueException('Compact matcher static method map is invalid.');
            }
            foreach ($paths as $path => $id) {
                if (!is_string($path) || !is_int($id) || !array_key_exists($id, $routes)) {
                    throw new \UnexpectedValueException('Compact matcher static route ID is invalid.');
                }
                $static[$method][$path] = $id;
            }
        }

        return $static;
    }

    /** @return array<string,list<string>> */
    private static function validateStaticAllowed(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher static allowed-method map must be an array.');
        }

        $allowed = [];
        foreach ($raw as $path => $methods) {
            if (!is_string($path)) {
                throw new \UnexpectedValueException('Compact matcher static allowed-method path is invalid.');
            }
            $allowed[$path] = self::validateMethodList($methods);
        }

        return $allowed;
    }

    /**
     * @param array<int,mixed> $routes
     * @return array<string,array<int,array<string,array<string,mixed>>>>
     */
    private static function validateDynamic(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher dynamic map must be an array.');
        }

        $dynamic = [];
        foreach ($raw as $method => $counts) {
            if (!is_string($method) || $method === '' || !is_array($counts)) {
                throw new \UnexpectedValueException('Compact matcher dynamic method map is invalid.');
            }
            foreach ($counts as $count => $prefixes) {
                if (!is_int($count) || $count < 0 || !is_array($prefixes)) {
                    throw new \UnexpectedValueException('Compact matcher segment-count map is invalid.');
                }
                foreach ($prefixes as $prefix => $bucket) {
                    if (!is_string($prefix)) {
                        throw new \UnexpectedValueException('Compact matcher prefix bucket is invalid.');
                    }
                    $dynamic[$method][$count][$prefix] = self::validateBucket($bucket, $routes, $validateRegex);
                }
            }
        }

        return $dynamic;
    }

    /**
     * @param array<int,mixed> $routes
     * @return array<string,mixed>
     */
    private static function validateBucket(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher dynamic bucket is invalid.');
        }
        $stepsRaw = $raw['steps'] ?? null;
        if (!is_array($stepsRaw) || !array_is_list($stepsRaw) || $stepsRaw === []) {
            throw new \UnexpectedValueException('Compact matcher dynamic steps are invalid.');
        }

        $steps = [];
        foreach ($stepsRaw as $step) {
            $steps[] = self::validateStep($step, $routes, $validateRegex);
        }

        $bucket = ['steps' => $steps];
        if (array_key_exists('fast_dispatch', $raw)) {
            $bucket['fast_dispatch'] = self::validateFastDispatch($raw['fast_dispatch'], $routes, $validateRegex);
        }

        return $bucket;
    }

    /**
     * @param array<int,mixed> $routes
     * @return array<string,mixed>
     */
    private static function validateStep(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher dynamic step is invalid.');
        }
        if (($raw['type'] ?? null) === 'pcre') {
            $regex = $raw['regex'] ?? null;
            $mapRaw = $raw['routes'] ?? null;
            if (!is_string($regex) || $regex === '' || !is_array($mapRaw) || $mapRaw === []) {
                throw new \UnexpectedValueException('Compact matcher PCRE step is invalid.');
            }
            if ($validateRegex && @preg_match($regex, '') === false) {
                throw new \UnexpectedValueException('Compact matcher PCRE cannot be compiled.');
            }
            $map = [];
            foreach ($mapRaw as $mark => $entry) {
                if (!is_string($mark) || $mark === '' || !is_array($entry)) {
                    throw new \UnexpectedValueException('Compact matcher PCRE route map is invalid.');
                }
                $id = $entry['id'] ?? null;
                if (!is_int($id) || !array_key_exists($id, $routes)) {
                    throw new \UnexpectedValueException('Compact matcher PCRE route ID is invalid.');
                }
                $map[$mark] = ['id' => $id, 'params' => self::validateParams($entry['params'] ?? null)];
            }

            return ['type' => 'pcre', 'regex' => $regex, 'routes' => $map];
        }
        if (($raw['type'] ?? null) !== 'fallback') {
            throw new \UnexpectedValueException('Compact matcher dynamic step type is invalid.');
        }
        $id = $raw['id'] ?? null;
        if (!is_int($id) || !array_key_exists($id, $routes)) {
            throw new \UnexpectedValueException('Compact matcher fallback route ID is invalid.');
        }

        return ['type' => 'fallback', 'segments' => self::validateSegments($raw['segments'] ?? null), 'id' => $id];
    }

    /**
     * @param array<int,mixed> $routes
     * @return array{segment:int,groups:array<string,list<array{regex:string,routes:array<string,array{id:int,params:list<string>}>}>>}
     */
    private static function validateFastDispatch(mixed $raw, array $routes, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher adaptive dispatch is invalid.');
        }
        $segment = $raw['segment'] ?? null;
        $groupsRaw = $raw['groups'] ?? null;
        if (!is_int($segment) || $segment < 0 || !is_array($groupsRaw) || $groupsRaw === []) {
            throw new \UnexpectedValueException('Compact matcher adaptive metadata is invalid.');
        }

        $groups = [];
        foreach ($groupsRaw as $literal => $stepsRaw) {
            if (!is_string($literal) || !is_array($stepsRaw) || !array_is_list($stepsRaw) || $stepsRaw === []) {
                throw new \UnexpectedValueException('Compact matcher adaptive literal group is invalid.');
            }
            $steps = [];
            foreach ($stepsRaw as $step) {
                if (!is_array($step) || !is_string($step['regex'] ?? null) || !is_array($step['routes'] ?? null)) {
                    throw new \UnexpectedValueException('Compact matcher adaptive PCRE step is invalid.');
                }
                $regex = $step['regex'];
                if ($validateRegex && @preg_match($regex, '') === false) {
                    throw new \UnexpectedValueException('Compact matcher adaptive PCRE cannot be compiled.');
                }
                $map = [];
                foreach ($step['routes'] as $mark => $entry) {
                    if (!is_string($mark) || !is_array($entry)) {
                        throw new \UnexpectedValueException('Compact matcher adaptive route map is invalid.');
                    }
                    $id = $entry['id'] ?? null;
                    if (!is_int($id) || !array_key_exists($id, $routes)) {
                        throw new \UnexpectedValueException('Compact matcher adaptive route ID is invalid.');
                    }
                    $map[$mark] = ['id' => $id, 'params' => self::validateParams($entry['params'] ?? null)];
                }
                $steps[] = ['regex' => $regex, 'routes' => $map];
            }
            $groups[$literal] = $steps;
        }

        return ['segment' => $segment, 'groups' => $groups];
    }

    /** @return array<int,array<string,array<string,mixed>>> */
    private static function validateDynamicAllowed(mixed $raw, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compact matcher dynamic allowed-method map must be an array.');
        }

        $allowed = [];
        foreach ($raw as $count => $prefixes) {
            if (!is_int($count) || $count < 0 || !is_array($prefixes)) {
                throw new \UnexpectedValueException('Compact matcher allowed-method count bucket is invalid.');
            }
            foreach ($prefixes as $prefix => $bucket) {
                if (!is_string($prefix) || !is_array($bucket)) {
                    throw new \UnexpectedValueException('Compact matcher allowed-method prefix bucket is invalid.');
                }
                if (($bucket['type'] ?? null) === 'fallback') {
                    $allowed[$count][$prefix] = ['type' => 'fallback'];
                    continue;
                }
                if (($bucket['type'] ?? null) !== 'literal' || !is_int($bucket['segment'] ?? null) || !is_array($bucket['groups'] ?? null)) {
                    throw new \UnexpectedValueException('Compact matcher allowed-method literal bucket is invalid.');
                }
                $groups = [];
                foreach ($bucket['groups'] as $literal => $entry) {
                    if (!is_string($literal) || !is_array($entry) || !is_string($entry['regex'] ?? null)) {
                        throw new \UnexpectedValueException('Compact matcher allowed-method entry is invalid.');
                    }
                    $regex = $entry['regex'];
                    if ($validateRegex && @preg_match($regex, '') === false) {
                        throw new \UnexpectedValueException('Compact matcher allowed-method PCRE cannot be compiled.');
                    }
                    $groups[$literal] = ['regex' => $regex, 'methods' => self::validateMethodList($entry['methods'] ?? null)];
                }
                $allowed[$count][$prefix] = ['type' => 'literal', 'segment' => $bucket['segment'], 'groups' => $groups];
            }
        }

        return $allowed;
    }

    /** @return list<string> */
    private static function validateParams(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \UnexpectedValueException('Compact matcher parameter list is invalid.');
        }
        foreach ($raw as $name) {
            if (!is_string($name) || $name === '') {
                throw new \UnexpectedValueException('Compact matcher parameter name is invalid.');
            }
        }

        return $raw;
    }

    /** @return list<string> */
    private static function validateMethodList(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new \UnexpectedValueException('Compact matcher method list is invalid.');
        }
        $seen = [];
        foreach ($raw as $method) {
            if (!is_string($method) || $method === '' || isset($seen[$method])) {
                throw new \UnexpectedValueException('Compact matcher method token is invalid or duplicated.');
            }
            $seen[$method] = true;
        }

        return array_keys($seen);
    }

    /** @return list<array<string,mixed>> */
    private static function validateSegments(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \UnexpectedValueException('Compact matcher fallback segments are invalid.');
        }
        foreach ($raw as $segment) {
            if (!is_array($segment) || !is_string($segment['type'] ?? null)) {
                throw new \UnexpectedValueException('Compact matcher fallback segment is invalid.');
            }
        }

        return $raw;
    }

    private static function routeIndex(mixed $route): int
    {
        $index = $route instanceof CompiledRoute
            ? $route->getIndex()
            : ExecutableRoutePayload::routeIndex($route);
        if (!is_int($index) || $index < 0) {
            throw new \UnexpectedValueException('Compact matcher route payload is invalid.');
        }

        return $index;
    }
}
