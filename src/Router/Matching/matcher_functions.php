<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

function matcher_should_warm_opcache(): bool
{
    if (!\function_exists('opcache_compile_file')) {
        return false;
    }

    if (\filter_var((string) \ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL) !== true) {
        return false;
    }

    if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
        if (\filter_var((string) \ini_get('opcache.enable_cli'), \FILTER_VALIDATE_BOOL) !== true) {
            return false;
        }
    }

    if (\function_exists('opcache_get_status') && \opcache_get_status(false) === false) {
        return false;
    }

    return true;
}

/**
 * @param list<array{type:'lit',val:string}|array{type:'var',name:string,regex?:string,call?:callable-string}> $segments
 */
function generated_matcher_render_dynamic_entry_condition(array $segments, string $indent): string
{
    $checks = [];
    foreach ($segments as $i => $part) {
        if ($part['type'] === 'lit') {
            $checks[] = "(\$segments[{$i}] ?? null) === " . \var_export($part['val'], true);

            continue;
        }

        if (isset($part['regex'])) {
            $checks[] = '\\preg_match(' . \var_export($part['regex'], true) . ", (string)(\$segments[{$i}] ?? '')) === 1";

            continue;
        }

        if (isset($part['call'])) {
            $checks[] = '\\call_user_func(' . \var_export($part['call'], true) . ", (string)(\$segments[{$i}] ?? ''))";
        }
    }

    return $checks === []
        ? 'true'
        : \implode(" &&\n" . $indent . '            ', $checks);
}

/**
 * @param array<string,int> $verbs
 */
function generated_matcher_render_verb_dispatch(array $verbs, string $indent): string
{
    $firstIdx = (int) \reset($verbs);
    $code = $indent . "switch (\$verb) {\n";
    foreach ($verbs as $method => $idx) {
        $code .= $indent . '    case ' . \var_export($method, true) . ":\n";
        $code .= $indent . "        return ['hit' => \$routes[{$idx}], 'params' => \$params, 'allowed' => []];\n";
    }

    if (!isset($verbs[HttpMethodEnum::HEAD->value]) && isset($verbs[HttpMethodEnum::GET->value])) {
        $getIdx = $verbs[HttpMethodEnum::GET->value];
        $code .= $indent . '    case ' . \var_export(HttpMethodEnum::HEAD->value, true) . ":\n";
        $code .= $indent . "        return ['hit' => \$routes[{$getIdx}], 'params' => \$params, 'allowed' => []];\n";
    }

    $code .= $indent . '    case ' . \var_export(HttpMethodEnum::OPTIONS->value, true) . ":\n";
    $code .= $indent . "        return ['hit' => \$routes[{$firstIdx}], 'params' => \$params, 'allowed' => []];\n";
    $code .= $indent . "    default:\n";
    foreach ($verbs as $method => $_idx) {
        $code .= $indent . '        $allowed[' . \var_export($method, true) . "] = true;\n";
    }
    if (isset($verbs[HttpMethodEnum::GET->value])) {
        $code .= $indent . '        $allowed[' . \var_export(HttpMethodEnum::HEAD->value, true) . "] = true;\n";
    }
    $code .= $indent . "        break;\n";

    return $code . ($indent . "}\n");
}

function sharded_matcher_alias_file_path(string $cacheDir, string $aliasesFileName): string
{
    return $cacheDir . \DIRECTORY_SEPARATOR . $aliasesFileName;
}

function sharded_matcher_file_key_for_path(string $path, string $shardRoot): string
{
    if ($path === '/' || $path === '') {
        return $shardRoot;
    }

    $trimmed = $path[0] === '/' ? \substr($path, 1) : $path;
    $pos = \strpos($trimmed, '/');

    return $pos === false ? $trimmed : \substr($trimmed, 0, $pos);
}

/**
 * @return array{0:CompiledRoute,1:array<string,string>}
 */
function sharded_matcher_match(
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
    $allowedSet = sharded_matcher_merge_allowed($allowedSet, sharded_matcher_extract_allowed($staticHost));
    if ($hit = sharded_matcher_extract_hit($staticHost)) {
        return $hit;
    }

    if ($host !== '*') {
        $grpAny = $loadGroup('*', $bucket);
        $wildcardLoaded = true;
        $hasCandidateGroup = $hasCandidateGroup || ($grpAny !== null);

        $staticAny = $tryStatic($grpAny, $method, $path);
        $allowedSet = sharded_matcher_merge_allowed($allowedSet, sharded_matcher_extract_allowed($staticAny));
        if ($hit = sharded_matcher_extract_hit($staticAny)) {
            return $hit;
        }
    }

    $segments = $explodePath($path);
    $dynamicHost = $tryDynamic($grpHost, $method, $segments);
    $allowedSet = sharded_matcher_merge_allowed($allowedSet, sharded_matcher_extract_allowed($dynamicHost));
    if ($hit = sharded_matcher_extract_hit($dynamicHost)) {
        return $hit;
    }

    if ($host !== '*') {
        if (!$wildcardLoaded) {
            $grpAny = $loadGroup('*', $bucket);
            $hasCandidateGroup = $hasCandidateGroup || ($grpAny !== null);
        }

        $dynamicAny = $tryDynamic($grpAny, $method, $segments);
        $allowedSet = sharded_matcher_merge_allowed($allowedSet, sharded_matcher_extract_allowed($dynamicAny));
        if ($hit = sharded_matcher_extract_hit($dynamicAny)) {
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
 * @return array{static: array<string, array<string, CompiledRoute>>, trie: array<string,mixed>}
 */
function sharded_matcher_normalize_group(mixed $raw): array
{
    if (!\is_array($raw)) {
        return ['static' => [], 'trie' => sharded_matcher_new_node()];
    }

    return [
        'static' => sharded_matcher_normalize_group_static($raw['static'] ?? null),
        'trie' => sharded_matcher_normalize_group_trie($raw['trie'] ?? null),
    ];
}

/**
 * @return array{0:string,1:string,2:string}
 */
function sharded_matcher_normalize_request(string $method, string $host, string $path): array
{
    return [\strtoupper($method), \strtolower($host ?: '*'), ($path === '' ? '/' : $path)];
}

/**
 * @param list<string> $winReserved
 */
function sharded_matcher_shard_file_path(string $cacheDir, string $hostKey, string $bucket, array $winReserved): string
{
    $bucketSafe = sharded_matcher_sanitize_for_filename($bucket, $winReserved);
    $name = ($hostKey === '*')
        ? $bucketSafe . '.php'
        : sharded_matcher_sanitize_for_filename($hostKey, $winReserved) . '.' . $bucketSafe . '.php';

    return $cacheDir . \DIRECTORY_SEPARATOR . $name;
}

function sharded_matcher_write_atomic_php_file(string $file, string $php): void
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
 * @return array{0:CompiledRoute,1:array<string,string>}|null
 */
function sharded_matcher_as_match_hit(mixed $value): ?array
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
function sharded_matcher_extract_allowed(mixed $result): array
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
function sharded_matcher_extract_hit(mixed $result): ?array
{
    if (!\is_array($result)) {
        return null;
    }

    return sharded_matcher_as_match_hit($result['hit'] ?? null);
}

/**
 * @param array<string,bool> $base
 * @param array<string,bool> $extra
 * @return array<string,bool>
 */
function sharded_matcher_merge_allowed(array $base, array $extra): array
{
    foreach ($extra as $verb => $flag) {
        if ($flag) {
            $base[$verb] = true;
        }
    }

    return $base;
}

/**
 * @return array{children:array<mixed,mixed>,param:null,routes:array<mixed,mixed>}
 */
function sharded_matcher_new_node(): array
{
    return ['children' => [], 'param' => null, 'routes' => []];
}

/**
 * @return array<string, array<string, CompiledRoute>>
 */
function sharded_matcher_normalize_group_static(mixed $rawStatic): array
{
    if (!\is_array($rawStatic)) {
        return [];
    }

    $static = [];
    foreach ($rawStatic as $path => $verbs) {
        if (!\is_string($path)) {
            continue;
        }

        $verbMap = sharded_matcher_normalize_group_verb_map($verbs);
        if ($verbMap !== []) {
            $static[$path] = $verbMap;
        }
    }

    return $static;
}

/**
 * @return array<string,mixed>
 */
function sharded_matcher_normalize_group_trie(mixed $rawTrie): array
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
 * @return array<string, CompiledRoute>
 */
function sharded_matcher_normalize_group_verb_map(mixed $verbs): array
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
function sharded_matcher_sanitize_for_filename(string $value, array $winReserved): string
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
