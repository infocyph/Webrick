<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

use Infocyph\Webrick\Router\Build\Artifact\ExecutableRoutePayload;
use Infocyph\Webrick\Router\Route\CompiledRoute;

function matcher_should_warm_opcache(): bool
{
    if (!function_exists('opcache_compile_file')) {
        return false;
    }
    if (!filter_var((string) ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
        return false;
    }
    if (
        (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg')
        && !filter_var((string) ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)
    ) {
        return false;
    }

    return !function_exists('opcache_get_status') || opcache_get_status(false) !== false;
}

/**
 * Write generated PHP metadata, validate the staged executable artifact, and
 * only then atomically replace the active cache file.
 *
 * @param callable(array<mixed>):void $validate
 */
function matcher_write_validated_atomic_php_file(string $file, string $php, callable $validate): void
{
    $tmp = $file . '.' . uniqid('', true) . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false) {
        throw new \RuntimeException("Failed to write cache temp file {$tmp}");
    }
    chmod($tmp, 0664);

    try {
        $blob = require $tmp;
        if (!is_array($blob)) {
            throw new \UnexpectedValueException('Generated cache file must return an array.');
        }
        $validate($blob);
    } catch (\Throwable $exception) {
        @unlink($tmp);

        throw new \RuntimeException('Generated cache validation failed before publication.', 0, $exception);
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);

        throw new \RuntimeException("Failed to move cache file into place {$file}");
    }
}

function matcher_materialize_cached_route(mixed $payload): CompiledRoute
{
    if ($payload instanceof CompiledRoute) {
        return $payload;
    }

    return ExecutableRoutePayload::decode($payload);
}

/** @param array<string,array{0:string,1:?string}> $aliasIndex */
function matcher_capture_route_alias(array &$aliasIndex, CompiledRoute $route): void
{
    $name = $route->getName();
    if ($name !== null && $name !== '') {
        $aliasIndex[$name] = [$route->getPath(), $route->getDomain()];
    }
}

/** @param array<string,true> $requirements */
function matcher_capture_middleware_requirements(array &$requirements, CompiledRoute $route): void
{
    foreach ($route->getMiddlewares() as $middleware) {
        if (!is_string($middleware) || class_exists($middleware) || function_exists($middleware)) {
            continue;
        }
        $name = strtolower(trim(explode(':', $middleware, 2)[0]));
        if ($name !== '' && \Infocyph\Webrick\Router\Dispatch\MiddlewareAliases::has($name)) {
            $requirements[$name] = true;
        }
    }
}

/** @return list<string> */
function matcher_normalize_middleware_requirements(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $requirements = [];
    foreach ($raw as $name) {
        if (!is_string($name)) {
            continue;
        }
        $name = strtolower(trim($name));
        if ($name !== '') {
            $requirements[$name] = true;
        }
    }

    return array_keys($requirements);
}

/** @return array<string,array{0:string,1:?string}> */
function matcher_normalize_alias_pairs(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $aliases = [];
    foreach ($raw as $name => $tuple) {
        if (!is_string($name) || !is_array($tuple)) {
            continue;
        }

        $path = $tuple[0] ?? null;
        $domain = $tuple[1] ?? null;
        if (is_string($path)) {
            $aliases[$name] = [$path, is_string($domain) ? $domain : null];
        }
    }

    return $aliases;
}

/** @return array<string,CompiledRoute|array<mixed>> */
function matcher_normalize_compiled_route_map(mixed $verbs): array
{
    if (!is_array($verbs)) {
        return [];
    }

    return array_filter(
        $verbs,
        static fn(mixed $route, mixed $verb): bool => is_string($verb)
            && ($route instanceof CompiledRoute || is_array($route)),
        ARRAY_FILTER_USE_BOTH,
    );
}

/** @param list<string> $winReserved */
function sharded_matcher_shard_file_path(string $cacheDir, string $hostKey, string $bucket, array $winReserved): string
{
    $bucketSafe = sharded_matcher_sanitize_for_filename($bucket, $winReserved);
    $name = $hostKey === '*'
        ? $bucketSafe . '.php'
        : sharded_matcher_sanitize_for_filename($hostKey, $winReserved) . '.' . $bucketSafe . '.php';

    return $cacheDir . DIRECTORY_SEPARATOR . $name;
}

/** @param list<string> $winReserved */
function sharded_matcher_sanitize_for_filename(string $value, array $winReserved): string
{
    $out = '';
    $prevUnderscore = false;
    $length = strlen($value);

    for ($index = 0; $index < $length; $index++) {
        $char = $value[$index];
        $ord = ord($char);
        $alphaNumeric = ($ord >= 48 && $ord <= 57)
            || ($ord >= 65 && $ord <= 90)
            || ($ord >= 97 && $ord <= 122);

        if ($alphaNumeric || $char === '.' || $char === '_' || $char === '-') {
            $out .= $char;
            $prevUnderscore = false;

            continue;
        }

        if (!$prevUnderscore) {
            $out .= '_';
            $prevUnderscore = true;
        }
    }

    $out = rtrim(ltrim($out, '.'), ' .');
    if ($out === '') {
        $out = '_';
    }
    if (in_array(strtoupper($out), $winReserved, true)) {
        $out = '_' . $out;
    }

    return $out;
}
