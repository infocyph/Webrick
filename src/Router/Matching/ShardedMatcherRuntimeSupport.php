<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

final class ShardedMatcherRuntimeSupport
{
    /**
     * @return array{0:CompiledRoute,1:array<string,string>}
     */
    public static function match(
        string $method,
        string $host,
        string $path,
        callable $normalize,
        callable $bucketForPath,
        callable $loadGroup,
        callable $tryStatic,
        callable $explodePath,
        callable $tryDynamic,
    ): array {
        /** @var array{0:string,1:string,2:string} $normalized */
        $normalized = $normalize($method, $host, $path);
        [$method, $host, $path] = $normalized;
        $bucket = $bucketForPath($path);

        $grpHost = $loadGroup($host, $bucket);
        $grpAny = null;
        $wildcardLoaded = false;
        $hasCandidateGroup = ($grpHost !== null);
        $allowedSet = [];

        $staticHost = $tryStatic($grpHost, $method, $path);
        $allowedSet = self::mergeAllowed($allowedSet, self::extractAllowed($staticHost));
        if ($hit = self::extractHit($staticHost)) {
            return $hit;
        }

        if ($host !== '*') {
            $grpAny = $loadGroup('*', $bucket);
            $wildcardLoaded = true;
            $hasCandidateGroup = $hasCandidateGroup || ($grpAny !== null);

            $staticAny = $tryStatic($grpAny, $method, $path);
            $allowedSet = self::mergeAllowed($allowedSet, self::extractAllowed($staticAny));
            if ($hit = self::extractHit($staticAny)) {
                return $hit;
            }
        }

        $segments = $explodePath($path);
        $dynamicHost = $tryDynamic($grpHost, $method, $segments);
        $allowedSet = self::mergeAllowed($allowedSet, self::extractAllowed($dynamicHost));
        if ($hit = self::extractHit($dynamicHost)) {
            return $hit;
        }

        if ($host !== '*') {
            if (!$wildcardLoaded) {
                $grpAny = $loadGroup('*', $bucket);
                $hasCandidateGroup = $hasCandidateGroup || ($grpAny !== null);
            }
            $dynamicAny = $tryDynamic($grpAny, $method, $segments);
            $allowedSet = self::mergeAllowed($allowedSet, self::extractAllowed($dynamicAny));
            if ($hit = self::extractHit($dynamicAny)) {
                return $hit;
            }
        }

        if (!$hasCandidateGroup) {
            throw new RouteNotFoundException($method, $path);
        }
        if ($allowedSet !== []) {
            throw new MethodNotAllowedException($method, $path, \array_keys($allowedSet));
        }

        throw new RouteNotFoundException($method, $path);
    }

    /**
     * @return array{0:CompiledRoute,1:array<string,string>}|null
     */
    private static function asMatchHit(mixed $value): ?array
    {
        if (
            !\is_array($value)
            || !isset($value[0], $value[1])
            || !$value[0] instanceof CompiledRoute
            || !\is_array($value[1])
        ) {
            return null;
        }

        $params = [];
        foreach ($value[1] as $k => $v) {
            if (\is_string($k) && \is_string($v)) {
                $params[$k] = $v;
            }
        }

        return [$value[0], $params];
    }

    /**
     * @return array<string,bool>
     */
    private static function extractAllowed(mixed $result): array
    {
        if (!\is_array($result) || !\is_array($result['allowed'] ?? null)) {
            return [];
        }

        $allowed = [];
        foreach ($result['allowed'] as $verb => $flag) {
            if (\is_string($verb) && $flag === true) {
                $allowed[$verb] = true;
            }
        }

        return $allowed;
    }

    /**
     * @return array{0:CompiledRoute,1:array<string,string>}|null
     */
    private static function extractHit(mixed $result): ?array
    {
        if (!\is_array($result)) {
            return null;
        }

        return self::asMatchHit($result['hit'] ?? null);
    }

    /**
     * @param array<string,bool> $base
     * @param array<string,bool> $extra
     * @return array<string,bool>
     */
    private static function mergeAllowed(array $base, array $extra): array
    {
        foreach ($extra as $verb => $flag) {
            if ($flag) {
                $base[$verb] = true;
            }
        }

        return $base;
    }
}
