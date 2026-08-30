<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Router\Route\CompiledRoute;

use function Opis\Closure\serialize as opis_serialize;
use function Opis\Closure\unserialize as opis_unserialize;

const MATCHER_ROUTE_ENVELOPE = 'wrc1.';

function matcher_serialize_cached_route(CompiledRoute $route): string
{
    return MATCHER_ROUTE_ENVELOPE . base64_encode(opis_serialize($route));
}

function matcher_unserialize_cached_route(string $payload): CompiledRoute
{
    if (!str_starts_with($payload, MATCHER_ROUTE_ENVELOPE)) {
        throw new \UnexpectedValueException('Invalid serialized route cache envelope.');
    }

    $serialized = base64_decode(substr($payload, strlen(MATCHER_ROUTE_ENVELOPE)), true);
    if ($serialized === false || $serialized === '') {
        throw new \UnexpectedValueException('Invalid serialized route cache payload.');
    }

    try {
        $route = opis_unserialize($serialized);
    } catch (\Throwable $exception) {
        throw new \UnexpectedValueException('Unable to unserialize route cache payload.', 0, $exception);
    }

    if (!$route instanceof CompiledRoute) {
        throw new \UnexpectedValueException('Serialized route cache payload must contain a CompiledRoute.');
    }

    return $route;
}

function matcher_should_warm_opcache(): bool
{
    if (!\function_exists('opcache_compile_file')) {
        return false;
    }

    if (!\filter_var((string) \ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL)) {
        return false;
    }

    if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
        if (!\filter_var((string) \ini_get('opcache.enable_cli'), \FILTER_VALIDATE_BOOL)) {
            return false;
        }
    }

    if (\function_exists('opcache_get_status') && \opcache_get_status(false) === false) {
        return false;
    }

    return true;
}

/**
 * Write generated PHP metadata, validate the staged executable artifact, and
 * only then atomically replace the active cache file.
 *
 * @param callable(array<mixed>):void $validate
 */
function matcher_write_validated_atomic_php_file(string $file, string $php, callable $validate): void
{
    $tmp = $file . '.' . \uniqid('', true) . '.tmp';
    if (\file_put_contents($tmp, $php, \LOCK_EX) === false) {
        throw new \RuntimeException("Failed to write cache temp file {$tmp}");
    }
    \chmod($tmp, 0664);

    try {
        $blob = require $tmp;
        if (!\is_array($blob)) {
            throw new \UnexpectedValueException('Generated cache file must return an array.');
        }
        $validate($blob);
    } catch (\Throwable $exception) {
        \unlink($tmp);

        throw new \RuntimeException('Generated cache validation failed before publication.', 0, $exception);
    }

    if (!\rename($tmp, $file)) {
        \unlink($tmp);

        throw new \RuntimeException("Failed to move cache file into place {$file}");
    }
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
    $materialize = '\\' . __NAMESPACE__ . '\\matcher_materialize_cached_route';
    $code = $indent . "switch (\$verb) {\n";
    foreach ($verbs as $method => $idx) {
        $code .= $indent . '    case ' . \var_export($method, true) . ":\n";
        $code .= $indent . '        return [\'hit\' => ($routes[' . $idx
            . '] ??= ' . $materialize . '($routePayloads[' . $idx
            . "])), 'params' => \$params, 'allowed' => []];\n";
    }

    if (!isset($verbs[HttpMethodEnum::HEAD->value]) && isset($verbs[HttpMethodEnum::GET->value])) {
        $getIdx = $verbs[HttpMethodEnum::GET->value];
        $code .= $indent . '    case ' . \var_export(HttpMethodEnum::HEAD->value, true) . ":\n";
        $code .= $indent . '        return [\'hit\' => ($routes[' . $getIdx
            . '] ??= ' . $materialize . '($routePayloads[' . $getIdx
            . "])), 'params' => \$params, 'allowed' => []];\n";
    }

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

function matcher_materialize_cached_route(mixed $payload): CompiledRoute
{
    if (\is_array($payload)) {
        return CompiledRoute::fromCachePayload($payload);
    }

    if (\is_string($payload)) {
        return matcher_unserialize_cached_route($payload);
    }

    throw new \RuntimeException('Invalid compiled-route cache payload.');
}

/**
 * @param array<string, array{0:string,1:?string}> $aliasIndex
 */
function matcher_capture_route_alias(array &$aliasIndex, CompiledRoute $route): void
{
    $name = $route->getName();
    if ($name !== null && $name !== '') {
        $aliasIndex[$name] = [$route->getPath(), $route->getDomain()];
    }
}

/**
 * @param array<string,true> $requirements
 */
function matcher_capture_middleware_requirements(array &$requirements, CompiledRoute $route): void
{
    foreach ($route->getMiddlewares() as $middleware) {
        if (!\is_string($middleware) || \class_exists($middleware) || \function_exists($middleware)) {
            continue;
        }
        $name = \strtolower(\trim(\explode(':', $middleware, 2)[0]));
        if ($name !== '' && \Infocyph\Webrick\Router\Dispatch\MiddlewareAliases::has($name)) {
            $requirements[$name] = true;
        }
    }
}

/** @return list<string> */
function matcher_normalize_middleware_requirements(mixed $raw): array
{
    if (!\is_array($raw)) {
        return [];
    }

    $requirements = [];
    foreach ($raw as $name) {
        if (!\is_string($name)) {
            continue;
        }
        $name = \strtolower(\trim($name));
        if ($name !== '') {
            $requirements[$name] = true;
        }
    }

    return \array_keys($requirements);
}

/**
 * @param array<string, array<string, array<string, bool>>> $guard
 */
function matcher_guard_route_uniqueness(array &$guard, string $host, string $verb, string $path): void
{
    if (isset($guard[$host][$verb][$path])) {
        throw new \LogicException("Duplicate route {$verb} {$host}{$path}");
    }

    $guard[$host][$verb][$path] = true;
}

/**
 * @param array<string, array<string, array<string, bool>>> $guard
 * @param callable(?string):string $canonicalHost
 * @return array{0:string,1:string,2:string}
 */
function matcher_prepare_route_registration(
    bool $finalized,
    array &$guard,
    callable $canonicalHost,
    CompiledRoute $route,
): array {
    if ($finalized) {
        throw new \LogicException('Cannot add routes after finalize().');
    }

    $host = $canonicalHost($route->getDomain());
    $verb = HttpMethodEnum::normalize($route->getMethod());
    $path = $route->getPath();

    matcher_guard_route_uniqueness($guard, $host, $verb, $path);

    return [$host, $verb, $path];
}

/**
 * @return array<string, array{0:string,1:?string}>
 */
function matcher_normalize_alias_pairs(mixed $raw): array
{
    if (!\is_array($raw)) {
        return [];
    }

    $aliases = [];
    foreach ($raw as $name => $tuple) {
        if (!\is_string($name) || !\is_array($tuple)) {
            continue;
        }

        $path = $tuple[0] ?? null;
        $domain = $tuple[1] ?? null;
        if (!\is_string($path)) {
            continue;
        }

        $aliases[$name] = [$path, \is_string($domain) ? $domain : null];
    }

    return $aliases;
}

/**
 * @return array<string, CompiledRoute|array<mixed>|string>
 */
function matcher_normalize_compiled_route_map(mixed $verbs): array
{
    if (!\is_array($verbs)) {
        return [];
    }

    return array_filter(
        $verbs,
        fn($route, $verb) => \is_string($verb)
            && ($route instanceof CompiledRoute || \is_array($route) || \is_string($route)),
        ARRAY_FILTER_USE_BOTH,
    );
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
 * @return array{
 *   static:array<string,array<string,CompiledRoute|array<mixed>|string>>,
 *   trie:array<string,mixed>
 * }
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

    $params = array_filter(
        $value[1],
        fn($v, $k) => \is_string($k) && \is_string($v),
        ARRAY_FILTER_USE_BOTH,
    );

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
 * @return array<string, array<string, CompiledRoute|array<mixed>|string>>
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
 * @return array<string, CompiledRoute|array<mixed>|string>
 */
function sharded_matcher_normalize_group_verb_map(mixed $verbs): array
{
    return matcher_normalize_compiled_route_map($verbs);
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
