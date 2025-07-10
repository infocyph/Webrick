<?php
// src/Router/Matching/FastRegexMatcher.php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Infocyph\Webrick\Router\Route\CompiledRoute;

final class FastRegexMatcher implements MatcherInterface
{
    /** @var array<string, array<string, array{regex:string,routes:array<int,CompiledRoute>}>> */
    private array $table;

    public function __construct(string $cacheFile)
    {
        $this->table = require $cacheFile;
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
        foreach ($m as $idx => $val) {
            if ($idx === 0 || $val === '') {
                continue;
            }
            $route = $bucket['routes'][$idx] ?? null;
            if ($route === null) {
                break; // should never happen
            }

            // ---------------- param extraction -----------------------------
            $params = [];
            if ($route->isDynamic()) {
                $names = $route->getVariables();
                $off   = $idx;             // shift capture offset
                foreach ($names as $i => $name) {
                    $params[$name] = $m[$off + $i];
                }
            }
            return [$route, $params];
        }

        throw new RouteNotFoundException($method, $path); // fallback
    }
}
