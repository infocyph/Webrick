<?php

// src/Router/Matching/FastRegexMatcher.php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

final class FastRegexMatcher implements MatcherInterface
{
    /** @var array<string, array<string, array{regex:string,routes:array<int,CompiledRoute>}>> */
    private array $table;

    public function __construct(string $cacheFile)
    {
        $loaded = require $cacheFile;

        if (!isset($loaded['_crc'], $loaded['_table'])) {
            throw new \RuntimeException('Fast-regex dump is missing CRC header.');
        }
        $calc = hash('crc32b', json_encode($loaded['_table'], JSON_THROW_ON_ERROR));
        if (!hash_equals($loaded['_crc'], $calc)) {
            // stale dump – fallback so app still boots
            throw new \UnexpectedValueException('Fast-regex dump CRC mismatch');
        }

        $this->table = $loaded['_table'];
    }

    public function add(CompiledRoute $route): void
    {
        throw new \LogicException('FastRegexMatcher is read-only after dump.');
    }

    public function match(string $method, string $host, string $path): array
    {
        $verb = strtoupper($method);
        $host = strtolower($host);

        // ---- lookup bucket --------------------------------------------------
        $bucket = $this->table[$verb][$host]
            ?? $this->table[$verb]['*']
            ?? null;

        if ($bucket === null) {
            throw new RouteNotFoundException($method, $path);
        }

        // ---- single branch-free regex --------------------------------------
        if (!\preg_match($bucket['regex'], $path, $m, 0, 0)) {
            throw new RouteNotFoundException($method, $path);
        }

        // first non-empty capture = route index
        $routes = $bucket['routes'];           // local for micro-speed
        foreach ($m as $idx => $val) {
            if ($idx === 0 || $val === '' || !isset($routes[$idx])) {
                continue;
            }
            $route = $routes[$idx];

            // ---------------- param extraction -----------------------------
            $params = [];
            if ($route->isDynamic()) {
                $names = $route->getVariables();
                $off = $idx;             // shift capture offset
                foreach ($names as $i => $name) {
                    $params[$name] = $m[$off + $i];
                }
            }
            return [$route, $params];
        }

        throw new RouteNotFoundException($method, $path); // fallback
    }
}
