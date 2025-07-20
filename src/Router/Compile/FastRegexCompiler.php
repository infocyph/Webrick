<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Compile;

use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * Dumps a PHP file returning:
 *
 * ```php
 * return [
 *   'GET' => [
 *     'example.com' => [
 *       'regex'   => '~^(?:/foo)|(bar/(?<id>[0-9]+))$~',
 *       'routes'  => [
 *           1 => $routeIdFoo ,   // capturing index 1
 *           2 => $routeIdBar ,   // capturing index 2
 *       ],
 *     ],
 *     '*' => … // wildcard-host bucket
 *   ],
 *   'POST' => [ … ],
 * ];
 * ```
 *
 * No objects, just ints and strings → APCu / OpCache friendly.
 */
final class FastRegexCompiler
{
    /** @param list<CompiledRoute> $routes */
    public static function dump(array $routes, string $targetFile): void
    {
        // group → [verb][host][] = route
        $buckets = [];

        foreach ($routes as $r) {
            $verb = $r->getMethod();                  // already upper-case
            $host = strtolower($r->getDomain() ?? '*');
            $buckets[$verb][$host][] = $r;
        }

        $out = [];
        foreach ($buckets as $verb => $byHost) {
            foreach ($byHost as $host => $list) {
                [$regex, $map] = self::buildRegex($list);
                $out[$verb][$host] = ['regex' => $regex, 'routes' => $map];
            }
        }

        // emit pretty-printed PHP for opcode cache
        $crc  = hash('crc32b', json_encode($out, JSON_THROW_ON_ERROR));
        $code = <<<PHP
        <?php
        return [
            '_crc'   => '$crc',
            '_table' => %s,
        ];
        PHP;
        $code = sprintf($code, var_export($out, true));
        file_put_contents($targetFile, $code);
        // Preload into OPcache if available
        if (\function_exists('opcache_compile_file')) {
            @opcache_compile_file($targetFile);
        }
    }

    /**
     * @param list<CompiledRoute> $routes (all share verb+host)
     * @return array{0:string,1:array<int,CompiledRoute>}
     */
    private static function buildRegex(array $routes): array
    {
        // sort by original declaration order → deterministic capturing groups
        usort($routes, static fn ($a, $b) => $a->getIndex() <=> $b->getIndex());

        $parts = [];
        $map   = [];
        $i     = 1;                                   // capturing group counter

        foreach ($routes as $route) {
            // ---- static path becomes literal
            if (!$route->isDynamic()) {
                $pattern = preg_quote($route->getPath(), '~');
                $parts[] = $pattern;                  // no capturing groups
                $map[$i++] = $route;
                continue;
            }

            // ---- already-built param regex, strip delimiters (^ … $)
            $body = substr($route->getRegex(), 3, -3);          // remove "#^" … "#D"
            // offset capturing indexes
            $captureCount = preg_match_all('/\((?!\?:|\?P<|\?\')/', $body);
            $parts[] = '(?:' . $body . ')';
            $map[$i] = $route;
            $i += $captureCount + 1;
        }

        $combined = '~^(?:' . implode('|', $parts) . ')$~';
        return [$combined, $map];
    }
}
