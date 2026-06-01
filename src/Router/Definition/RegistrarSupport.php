<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

use Infocyph\Webrick\Router\Definition\Attribute\Cors;
use Infocyph\Webrick\Router\Definition\Attribute\Produces;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

final class RegistrarSupport
{
    /**
     * @param list<string|object> $group
     * @param list<string|object> $route
     * @return list<string|object|array{__alias:true,key:non-empty-string,params:list<string>}>
     */
    public static function mergeMiddlewareWithAliasOverrides(array $group, array $route): array
    {
        $result = [];
        $aliasPos = [];

        $push = static function (string|object $mw) use (&$result, &$aliasPos): void {
            $spec = \is_string($mw) ? self::parseAliasSpec($mw) : null;
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
     * @param array<mixed> $items
     * @return list<string|object>
     */
    public static function normalizeMiddlewareList(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (\is_string($item) || \is_object($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param string|array<string,mixed>|null $nameOrOpts
     * @return array{0:?string,1:list<string|object>,2:list<string>,3:array{produces?:Produces,cors?:Cors}}
     */
    public static function normalizeOptions(string|array|null $nameOrOpts): array
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
        $extraMiddleware = self::normalizeMiddlewareList($mw);

        $aliasesRaw = $nameOrOpts['aliases'] ?? ($nameOrOpts['alias'] ?? []);
        $aliases = \is_array($aliasesRaw) ? $aliasesRaw : [$aliasesRaw];
        $normalizedAliases = [];
        foreach ($aliases as $alias) {
            if (\is_string($alias) && $alias !== '') {
                $normalizedAliases[] = $alias;
            }
        }

        $attrs = self::normalizeRouteAttributes($nameOrOpts['attributes'] ?? null);

        return [$name, $extraMiddleware, $normalizedAliases, $attrs];
    }

    /**
     * @return array{produces?:Produces,cors?:Cors}
     */
    public static function normalizeRouteAttributes(mixed $attrs): array
    {
        if (!\is_array($attrs)) {
            return [];
        }

        $normalized = [];
        if (($attrs['produces'] ?? null) instanceof Produces) {
            $normalized['produces'] = $attrs['produces'];
        }
        if (($attrs['cors'] ?? null) instanceof Cors) {
            $normalized['cors'] = $attrs['cors'];
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $items
     * @return list<string>
     */
    public static function normalizeStringList(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (\is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @return array<string,string>
     */
    public static function normalizeStringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && \is_string($item) && $item !== '') {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @return array{__alias:true,key:non-empty-string,params:list<string>}|null
     */
    public static function parseAliasSpec(string $s): ?array
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
     * @return array{
     *   0:string,
     *   1:list<string>|null,
     *   2:list<string>|null,
     *   3:array<string,string>,
     *   4:list<string|object>,
     *   5:string
     * }
     */
    public static function parseResourceOptions(array $opts): array
    {
        $param = \is_string($opts['param'] ?? null) ? $opts['param'] : 'id';
        $only = (isset($opts['only']) && \is_array($opts['only'])) ? self::normalizeStringList($opts['only']) : null;
        $except = (isset($opts['except']) && \is_array($opts['except'])) ? self::normalizeStringList($opts['except']) : null;
        $names = self::normalizeStringMap($opts['names'] ?? null);
        $mwAll = (isset($opts['middleware']) && \is_array($opts['middleware']))
            ? self::normalizeMiddlewareList($opts['middleware'])
            : [];
        $patchAction = \is_string($opts['patch_action'] ?? null) ? $opts['patch_action'] : 'update';

        return [$param, $only, $except, $names, $mwAll, $patchAction];
    }

    /**
     * @param list<string|object|array{__alias:true,key:non-empty-string,params:list<string>}> $list
     * @return list<string|object>
     */
    public static function resolveAliasMiddleware(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $resolved = \is_array($item)
                ? self::resolveAliasItem($item)
                : self::normalizeResolvedMiddleware($item);

            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    private static function normalizeResolvedMiddleware(mixed $resolved): string|object|null
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
     * @param array{__alias:true,key:non-empty-string,params:list<string>} $item
     */
    private static function resolveAliasItem(array $item): string|object|null
    {
        $spec = $item['key'];
        if ($item['params'] !== []) {
            $spec .= ':' . \implode(',', $item['params']);
        }

        return self::normalizeResolvedMiddleware(MiddlewareAliases::resolveString($spec));
    }

    // Group-shape normalization lives in RegistrarGroupSupport.
}
