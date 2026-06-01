<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition;

use Closure;
use InvalidArgumentException;

final class RegistrarGroupSupport
{
    /**
     * @param string|array<string,mixed>|null $prefix
     * @param string|array<string,mixed>|Closure|null $domain
     * @param list<mixed>|Closure $middleware
     * @return array{0:?string,1:?string,2:list<string|object>,3:?string,4:Closure}
     */
    public static function normalizeGroupInputs(
        array|string|null $prefix,
        string|array|Closure|null $domain,
        array|Closure $middleware,
        string|Closure|null $namePrefix,
        ?Closure $callback,
    ): array {
        if (\is_array($prefix)) {
            $opts = $prefix;
            $callback = $domain instanceof Closure ? $domain : $callback;
            [$prefix, $domain, $middleware, $namePrefix] = self::readGroupInputOptions($opts);
        }

        [$domain, $middleware, $namePrefix, $callback] = self::resolveImplicitGroupCallback(
            $domain,
            $middleware,
            $namePrefix,
            $callback,
        );

        if (!$callback instanceof Closure) {
            throw new InvalidArgumentException('A group callback Closure is required.');
        }

        return [
            self::toNullableString($prefix),
            self::toNullableString($domain),
            RegistrarSupport::normalizeMiddlewareList(self::toArray($middleware)),
            self::toNullableString($namePrefix),
            $callback,
        ];
    }

    /**
     * @param array<string,mixed> $opts
     * @return array{0:?string,1:?string,2:array<mixed>,3:?string}
     */
    private static function readGroupInputOptions(array $opts): array
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
     * @param string|array<mixed>|Closure|null $domain
     * @param array<mixed>|Closure $middleware
     * @return array{0:?string,1:array<mixed>,2:?string,3:?Closure}
     */
    private static function resolveImplicitGroupCallback(
        string|array|Closure|null $domain,
        array|Closure $middleware,
        string|Closure|null $namePrefix,
        ?Closure $callback,
    ): array {
        if ($callback === null && $domain instanceof Closure) {
            return [null, self::toArray($middleware), self::toNullableString($namePrefix), $domain];
        }
        if ($callback === null && $middleware instanceof Closure) {
            return [self::toNullableString($domain), [], self::toNullableString($namePrefix), $middleware];
        }
        if ($callback === null && $namePrefix instanceof Closure) {
            return [self::toNullableString($domain), self::toArray($middleware), null, $namePrefix];
        }

        return [
            self::toNullableString($domain),
            self::toArray($middleware),
            self::toNullableString($namePrefix),
            $callback,
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function toArray(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function toNullableString(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
