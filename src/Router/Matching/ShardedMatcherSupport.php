<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Route\CompiledRoute;

/**
 * @phpstan-type VerbRouteMap array<string, CompiledRoute>
 * @phpstan-type StaticBucket array<string, VerbRouteMap>
 * @phpstan-type Group array{static: StaticBucket, trie: array<string,mixed>}
 */
final class ShardedMatcherSupport
{
    public static function aliasFilePath(string $cacheDir, string $aliasesFileName): string
    {
        return $cacheDir . \DIRECTORY_SEPARATOR . $aliasesFileName;
    }

    public static function fileKeyForPath(string $path, string $shardRoot): string
    {
        if ($path === '/' || $path === '') {
            return $shardRoot;
        }
        $trimmed = $path[0] === '/' ? \substr($path, 1) : $path;
        $pos = \strpos($trimmed, '/');

        return $pos === false ? $trimmed : \substr($trimmed, 0, $pos);
    }

    /**
     * @return Group
     */
    public static function normalizeGroup(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return ['static' => [], 'trie' => self::newNode()];
        }

        return [
            'static' => self::normalizeGroupStatic($raw['static'] ?? null),
            'trie' => self::normalizeGroupTrie($raw['trie'] ?? null),
        ];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    public static function normalizeRequest(string $method, string $host, string $path): array
    {
        return [\strtoupper($method), \strtolower($host ?: '*'), ($path === '' ? '/' : $path)];
    }

    /**
     * @param list<string> $winReserved
     */
    public static function shardFilePath(string $cacheDir, string $hostKey, string $bucket, array $winReserved): string
    {
        $bucketSafe = self::sanitizeForFilename($bucket, $winReserved);
        $name = ($hostKey === '*')
            ? $bucketSafe . '.php'
            : self::sanitizeForFilename($hostKey, $winReserved) . '.' . $bucketSafe . '.php';

        return $cacheDir . \DIRECTORY_SEPARATOR . $name;
    }

    public static function writeAtomicPhpFile(string $file, string $php): void
    {
        $tmp = $file . '.' . \uniqid('', true) . '.tmp';
        if (\file_put_contents($tmp, $php, \LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write cache temp file {$tmp}");
        }
        \chmod($tmp, 0664);

        if (!\rename($tmp, $file)) {
            \unlink($tmp);

            throw new \RuntimeException("Failed to move cache file into place {$file}");
        }
    }

    /**
     * @return array{children:array<mixed,mixed>,param:null,routes:array<mixed,mixed>}
     */
    private static function newNode(): array
    {
        return ['children' => [], 'param' => null, 'routes' => []];
    }

    /**
     * @return StaticBucket
     */
    private static function normalizeGroupStatic(mixed $rawStatic): array
    {
        if (!\is_array($rawStatic)) {
            return [];
        }

        $static = [];
        foreach ($rawStatic as $path => $verbs) {
            if (!\is_string($path)) {
                continue;
            }

            $verbMap = self::normalizeGroupVerbMap($verbs);
            if ($verbMap !== []) {
                $static[$path] = $verbMap;
            }
        }

        return $static;
    }

    /**
     * @return array<string,mixed>
     */
    private static function normalizeGroupTrie(mixed $rawTrie): array
    {
        $trie = [];
        if (\is_array($rawTrie)) {
            foreach ($rawTrie as $k => $v) {
                if (\is_string($k)) {
                    $trie[$k] = $v;
                }
            }
        }

        if (!\is_array($trie['children'] ?? null)) {
            $trie['children'] = [];
        }
        if (!\array_key_exists('param', $trie) || (!\is_array($trie['param']) && $trie['param'] !== null)) {
            $trie['param'] = null;
        }
        if (!\is_array($trie['routes'] ?? null)) {
            $trie['routes'] = [];
        }

        return $trie;
    }

    /**
     * @return VerbRouteMap
     */
    private static function normalizeGroupVerbMap(mixed $verbs): array
    {
        if (!\is_array($verbs)) {
            return [];
        }

        $verbMap = [];
        foreach ($verbs as $verb => $route) {
            if (\is_string($verb) && $route instanceof CompiledRoute) {
                $verbMap[$verb] = $route;
            }
        }

        return $verbMap;
    }

    /**
     * @param list<string> $winReserved
     */
    private static function sanitizeForFilename(string $value, array $winReserved): string
    {
        $out = '';
        $prevUnderscore = false;
        $len = \strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            $ord = \ord($ch);
            $isAlphaNum = ($ord >= 48 && $ord <= 57) || ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122);

            if ($isAlphaNum || $ch === '.' || $ch === '_' || $ch === '-') {
                $out .= $ch;
                $prevUnderscore = false;

                continue;
            }

            if (!$prevUnderscore) {
                $out .= '_';
                $prevUnderscore = true;
            }
        }

        $out = \ltrim($out, '.');
        $out = \rtrim($out, ' .');
        if ($out === '') {
            $out = '_';
        }

        if (\in_array(\strtoupper($out), $winReserved, true)) {
            $out = '_' . $out;
        }

        return $out;
    }
}
