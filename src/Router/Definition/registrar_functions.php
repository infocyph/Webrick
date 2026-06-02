<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

use Closure;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use InvalidArgumentException;

/**
 * @param list<string|object> $group
 * @param list<string|object> $route
 * @return list<string|object|array{__alias:true,key:non-empty-string,params:list<string>}>
 */
function registrar_merge_middleware_with_alias_overrides(array $group, array $route): array
{
    $result = [];
    $aliasPos = [];

    $push = static function (string|object $mw) use (&$result, &$aliasPos): void {
        $spec = \is_string($mw) ? registrar_parse_alias_spec($mw) : null;
        if ($spec !== null) {
            if (isset($aliasPos[$spec['key']])) {
                unset($result[$aliasPos[$spec['key']]]);
            }
            $result[] = $spec;
            $aliasPos[$spec['key']] = \array_key_last($result);

            return;
        }

        $result[] = $mw;
    };

    foreach ($group as $mw) {
        $push($mw);
    }
    foreach ($route as $mw) {
        $push($mw);
    }

    return $result;
}

/**
 * @param string|array<string,mixed>|null $prefix
 * @param string|array<string,mixed>|Closure|null $domain
 * @param list<mixed>|Closure $middleware
 * @return array{0:?string,1:?string,2:list<string|object>,3:?string,4:Closure}
 */
function registrar_normalize_group_inputs(
    array|string|null $prefix,
    string|array|Closure|null $domain,
    array|Closure $middleware,
    string|Closure|null $namePrefix,
    ?Closure $callback,
): array {
    if (\is_array($prefix)) {
        $opts = $prefix;
        $callback = $domain instanceof Closure ? $domain : $callback;
        [$prefix, $domain, $middleware, $namePrefix] = registrar_read_group_input_options($opts);
    }

    [$domain, $middleware, $namePrefix, $callback] = registrar_resolve_implicit_group_callback(
        $domain,
        $middleware,
        $namePrefix,
        $callback,
    );

    if (!$callback instanceof Closure) {
        throw new InvalidArgumentException('A group callback Closure is required.');
    }

    return [
        registrar_to_nullable_string($prefix),
        registrar_to_nullable_string($domain),
        registrar_normalize_middleware_list(registrar_to_array($middleware)),
        registrar_to_nullable_string($namePrefix),
        $callback,
    ];
}

/**
 * @param string|array<string,mixed>|null $nameOrOpts
 * @return array{0:?string,1:list<string|object>,2:list<string>,3:array{produces?:\Infocyph\Webrick\Router\Definition\Attribute\Produces,cors?:\Infocyph\Webrick\Router\Definition\Attribute\Cors}}
 */
function registrar_normalize_options(string|array|null $nameOrOpts): array
{
    if ($nameOrOpts === null) {
        return [null, [], [], []];
    }

    if (\is_string($nameOrOpts)) {
        return [$nameOrOpts, [], [], []];
    }

    $name = null;
    if (isset($nameOrOpts['name']) && \is_string($nameOrOpts['name'])) {
        $name = $nameOrOpts['name'];
    } elseif (isset($nameOrOpts['as']) && \is_string($nameOrOpts['as'])) {
        $name = $nameOrOpts['as'];
    }

    $mw = $nameOrOpts['middleware'] ?? [];
    if (!\is_array($mw)) {
        $mw = [];
    }

    $aliasesRaw = $nameOrOpts['aliases'] ?? ($nameOrOpts['alias'] ?? []);
    $aliases = \is_array($aliasesRaw) ? $aliasesRaw : [$aliasesRaw];
    $normalizedAliases = [];
    foreach ($aliases as $alias) {
        if (\is_string($alias) && $alias !== '') {
            $normalizedAliases[] = $alias;
        }
    }

    return [
        $name,
        registrar_normalize_middleware_list($mw),
        $normalizedAliases,
        registrar_normalize_route_attributes($nameOrOpts['attributes'] ?? null),
    ];
}

/**
 * @param array<string,mixed> $opts
 * @return array{
 *   0:string,
 *   1:list<string>|null,
 *   2:list<string>|null,
 *   3:array<string,string>,
 *   4:list<string|object>,
 *   5:string
 * }
 */
function registrar_parse_resource_options(array $opts): array
{
    $param = \is_string($opts['param'] ?? null) ? $opts['param'] : 'id';
    $only = (isset($opts['only']) && \is_array($opts['only'])) ? registrar_normalize_string_list($opts['only']) : null;
    $except = (isset($opts['except']) && \is_array($opts['except'])) ? registrar_normalize_string_list($opts['except']) : null;
    $mwAll = (isset($opts['middleware']) && \is_array($opts['middleware']))
        ? registrar_normalize_middleware_list($opts['middleware'])
        : [];

    return [
        $param,
        $only,
        $except,
        registrar_normalize_string_map($opts['names'] ?? null),
        $mwAll,
        \is_string($opts['patch_action'] ?? null) ? $opts['patch_action'] : 'update',
    ];
}

/**
 * @param list<string|object|array{__alias:true,key:non-empty-string,params:list<string>}> $list
 * @return list<string|object>
 */
function registrar_resolve_alias_middleware(array $list): array
{
    $out = [];
    foreach ($list as $item) {
        $resolved = \is_array($item)
            ? registrar_resolve_alias_item($item)
            : registrar_normalize_resolved_middleware($item);

        if ($resolved !== null) {
            $out[] = $resolved;
        }
    }

    return $out;
}

/**
 * @param array<mixed> $items
 * @return list<string|object>
 */
function registrar_normalize_middleware_list(array $items): array
{
    return \array_values(\array_filter(
        $items,
        static fn(mixed $item): bool => \is_string($item) || \is_object($item),
    ));
}

function registrar_normalize_resolved_middleware(mixed $resolved): string|object|null
{
    if (\is_object($resolved)) {
        return $resolved;
    }

    if (\is_string($resolved) && $resolved !== '') {
        return $resolved;
    }

    return null;
}

/**
 * @return array{
 *   produces?:\Infocyph\Webrick\Router\Definition\Attribute\Produces,
 *   cors?:\Infocyph\Webrick\Router\Definition\Attribute\Cors
 * }
 */
function registrar_normalize_route_attributes(mixed $attrs): array
{
    if (!\is_array($attrs)) {
        return [];
    }

    $normalized = [];
    if (($attrs['produces'] ?? null) instanceof \Infocyph\Webrick\Router\Definition\Attribute\Produces) {
        $normalized['produces'] = $attrs['produces'];
    }
    if (($attrs['cors'] ?? null) instanceof \Infocyph\Webrick\Router\Definition\Attribute\Cors) {
        $normalized['cors'] = $attrs['cors'];
    }

    return $normalized;
}

/**
 * @param array<mixed> $items
 * @return list<string>
 */
function registrar_normalize_string_list(array $items): array
{
    return \array_values(\array_filter(
        $items,
        static fn(mixed $item): bool => \is_string($item) && $item !== '',
    ));
}

/**
 * @return array<string,string>
 */
function registrar_normalize_string_map(mixed $value): array
{
    if (!\is_array($value)) {
        return [];
    }

    return array_filter($value, fn($item, $key) => \is_string($key) && \is_string($item) && $item !== '', ARRAY_FILTER_USE_BOTH);
}

/**
 * @return array{__alias:true,key:non-empty-string,params:list<string>}|null
 */
function registrar_parse_alias_spec(string $s): ?array
{
    if (\class_exists($s)) {
        return null;
    }

    [$name, $paramStr] = \explode(':', $s, 2) + [1 => null];
    $key = \strtolower(\trim((string) $name));

    if ($key === '' || !MiddlewareAliases::has($key)) {
        return null;
    }

    $params = [];
    if ($paramStr !== null && $paramStr !== '') {
        foreach (\explode(',', $paramStr) as $part) {
            $params[] = \trim($part);
        }
    }

    return ['__alias' => true, 'key' => $key, 'params' => $params];
}

/**
 * @param array<string,mixed> $opts
 * @return array{0:?string,1:?string,2:array<mixed>,3:?string}
 */
function registrar_read_group_input_options(array $opts): array
{
    return [
        isset($opts['prefix']) && \is_string($opts['prefix']) ? $opts['prefix'] : null,
        isset($opts['domain']) && \is_string($opts['domain']) ? $opts['domain'] : null,
        isset($opts['middleware']) && \is_array($opts['middleware']) ? $opts['middleware'] : [],
        isset($opts['name']) && \is_string($opts['name'])
            ? $opts['name']
            : ((isset($opts['as']) && \is_string($opts['as'])) ? $opts['as'] : null),
    ];
}

/**
 * @param array{__alias:true,key:non-empty-string,params:list<string>} $item
 */
function registrar_resolve_alias_item(array $item): string|object|null
{
    $spec = $item['key'];
    if ($item['params'] !== []) {
        $spec .= ':' . \implode(',', $item['params']);
    }

    return registrar_normalize_resolved_middleware(MiddlewareAliases::resolveString($spec));
}

/**
 * @param string|array<mixed>|Closure|null $domain
 * @param array<mixed>|Closure $middleware
 * @return array{0:?string,1:array<mixed>,2:?string,3:?Closure}
 */
function registrar_resolve_implicit_group_callback(
    string|array|Closure|null $domain,
    array|Closure $middleware,
    string|Closure|null $namePrefix,
    ?Closure $callback,
): array {
    if ($callback === null && $domain instanceof Closure) {
        return [null, registrar_to_array($middleware), registrar_to_nullable_string($namePrefix), $domain];
    }
    if ($callback === null && $middleware instanceof Closure) {
        return [registrar_to_nullable_string($domain), [], registrar_to_nullable_string($namePrefix), $middleware];
    }
    if ($callback === null && $namePrefix instanceof Closure) {
        return [registrar_to_nullable_string($domain), registrar_to_array($middleware), null, $namePrefix];
    }

    return [
        registrar_to_nullable_string($domain),
        registrar_to_array($middleware),
        registrar_to_nullable_string($namePrefix),
        $callback,
    ];
}

/**
 * @return array<mixed>
 */
function registrar_to_array(mixed $value): array
{
    return \is_array($value) ? $value : [];
}

function registrar_to_nullable_string(mixed $value): ?string
{
    return \is_string($value) ? $value : null;
}
