<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

/** Validates matcher IR loaded from trusted-but-versioned PHP cache artifacts. */
final class CompiledMatcherIndexValidator
{
    private function __construct() {}

    /** @return array<string,array<string,mixed>> */
    public static function validateHosts(mixed $raw, bool $validateRegex = false): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compiled matcher hosts must be an array.');
        }

        $hosts = [];
        foreach ($raw as $host => $group) {
            if (!is_string($host) || $host === '') {
                throw new \UnexpectedValueException('Compiled matcher host key is invalid.');
            }
            $hosts[$host] = self::validateGroup($group, $validateRegex);
        }

        return $hosts;
    }

    /** @return array{static:array<string,array<string,mixed>>,dynamic:array<string,array<int,array<string,array<string,mixed>>>>} */
    public static function validateGroup(mixed $raw, bool $validateRegex = false): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compiled matcher group must be an array.');
        }

        return [
            'static' => self::validateStatic($raw['static'] ?? null),
            'dynamic' => self::validateDynamic($raw['dynamic'] ?? null, $validateRegex),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function validateStatic(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compiled matcher static map must be an array.');
        }

        $static = [];
        foreach ($raw as $method => $paths) {
            if (!is_string($method) || $method === '' || !is_array($paths)) {
                throw new \UnexpectedValueException('Compiled matcher static method map is invalid.');
            }
            foreach ($paths as $path => $route) {
                if (!is_string($path)) {
                    throw new \UnexpectedValueException('Compiled matcher static path is invalid.');
                }
                self::validateRoute($route);
                $static[$method][$path] = $route;
            }
        }

        return $static;
    }

    /** @return array<string,array<int,array<string,array<string,mixed>>>> */
    private static function validateDynamic(mixed $raw, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compiled matcher dynamic map must be an array.');
        }

        $dynamic = [];
        foreach ($raw as $method => $counts) {
            if (!is_string($method) || $method === '' || !is_array($counts)) {
                throw new \UnexpectedValueException('Compiled matcher dynamic method map is invalid.');
            }
            foreach ($counts as $count => $prefixes) {
                if (!is_int($count) || $count < 0 || !is_array($prefixes)) {
                    throw new \UnexpectedValueException('Compiled matcher segment-count bucket is invalid.');
                }
                foreach ($prefixes as $prefix => $bucket) {
                    if (!is_string($prefix)) {
                        throw new \UnexpectedValueException('Compiled matcher prefix bucket is invalid.');
                    }
                    $dynamic[$method][$count][$prefix] = self::validateBucket($bucket, $validateRegex);
                }
            }
        }

        return $dynamic;
    }

    /** @return array{steps:list<array<string,mixed>>} */
    private static function validateBucket(mixed $raw, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compiled matcher dynamic bucket is invalid.');
        }

        $stepsRaw = $raw['steps'] ?? null;
        if (!is_array($stepsRaw) || !array_is_list($stepsRaw) || $stepsRaw === []) {
            throw new \UnexpectedValueException('Compiled matcher dynamic steps are invalid.');
        }

        $steps = [];
        foreach ($stepsRaw as $step) {
            $steps[] = self::validateStep($step, $validateRegex);
        }

        return ['steps' => $steps];
    }

    /** @return array<string,mixed> */
    private static function validateStep(mixed $raw, bool $validateRegex): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compiled matcher dynamic step is invalid.');
        }

        $type = $raw['type'] ?? null;
        if ($type === 'pcre') {
            return self::validatePcreStep($raw, $validateRegex);
        }
        if ($type !== 'fallback') {
            throw new \UnexpectedValueException('Compiled matcher dynamic step type is invalid.');
        }

        $segments = self::validateSegments($raw['segments'] ?? null);
        $route = $raw['route'] ?? null;
        self::validateRoute($route);

        return ['type' => 'fallback', 'segments' => $segments, 'route' => $route];
    }

    /** @return array{type:'pcre',regex:string,routes:array<string,array{route:mixed,params:array<string,string>}>} */
    private static function validatePcreStep(array $raw, bool $validateRegex): array
    {
        $regex = $raw['regex'] ?? null;
        $routesRaw = $raw['routes'] ?? null;
        if (!is_string($regex) || $regex === '' || !is_array($routesRaw)) {
            throw new \UnexpectedValueException('Compiled matcher PCRE step payload is invalid.');
        }
        if ($validateRegex && @preg_match($regex, '') === false) {
            throw new \UnexpectedValueException('Compiled matcher PCRE step cannot be compiled.');
        }

        $routes = [];
        foreach ($routesRaw as $mark => $entry) {
            if (!is_string($mark) || $mark === '' || !is_array($entry)) {
                throw new \UnexpectedValueException('Compiled matcher PCRE route map is invalid.');
            }
            $route = $entry['route'] ?? null;
            self::validateRoute($route);
            $routes[$mark] = [
                'route' => $route,
                'params' => self::validateParams($entry['params'] ?? null),
            ];
        }
        if ($routes === []) {
            throw new \UnexpectedValueException('Compiled matcher PCRE step has no routes.');
        }

        return ['type' => 'pcre', 'regex' => $regex, 'routes' => $routes];
    }

    /** @return array<string,string> */
    private static function validateParams(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Compiled matcher parameter map is invalid.');
        }

        $params = [];
        foreach ($raw as $capture => $name) {
            if (
                !is_string($capture)
                || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $capture) !== 1
                || !is_string($name)
                || $name === ''
            ) {
                throw new \UnexpectedValueException('Compiled matcher parameter capture is invalid.');
            }
            $params[$capture] = $name;
        }

        return $params;
    }

    /** @return list<array<string,mixed>> */
    private static function validateSegments(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \UnexpectedValueException('Compiled matcher fallback segments are invalid.');
        }

        $segments = [];
        foreach ($raw as $segment) {
            if (!is_array($segment)) {
                throw new \UnexpectedValueException('Compiled matcher fallback segment is invalid.');
            }
            $type = $segment['type'] ?? null;
            if ($type === 'lit') {
                if (!is_string($segment['val'] ?? null)) {
                    throw new \UnexpectedValueException('Compiled matcher fallback literal is invalid.');
                }
            } elseif ($type === 'var') {
                if (!is_string($segment['name'] ?? null)) {
                    throw new \UnexpectedValueException('Compiled matcher fallback variable is invalid.');
                }
                $regex = $segment['regex'] ?? null;
                $call = $segment['call'] ?? null;
                if (!is_string($regex) && (!is_string($call) || !is_callable($call))) {
                    throw new \UnexpectedValueException('Compiled matcher fallback constraint is unavailable.');
                }
            } else {
                throw new \UnexpectedValueException('Compiled matcher fallback segment type is invalid.');
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    private static function validateRoute(mixed $route): void
    {
        if ($route instanceof CompiledRoute) {
            return;
        }
        if (!is_array($route) || ExecutableRoutePayload::routeIndex($route) === null) {
            throw new \UnexpectedValueException('Compiled matcher route payload is invalid.');
        }
    }
}
