<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

/**
 * Converts cache-safe handlers to scalar descriptors without changing callable
 * binding semantics.
 */
final class CachedHandlerNormalizer
{
    /**
     * @return array{0:string,1:string}|string|null
     */
    public static function normalize(mixed $handler): array|string|null
    {
        if (\is_string($handler)) {
            return $handler;
        }
        if (\is_array($handler) && \count($handler) === 2 && \is_string($handler[0]) && \is_string($handler[1])) {
            return [$handler[0], $handler[1]];
        }
        if (!$handler instanceof \Closure) {
            return null;
        }

        return self::normalizeClosure($handler);
    }

    /**
     * @return array{0:string,1:string}|string|null
     */
    private static function normalizeClosure(\Closure $handler): array|string|null
    {
        $reflection = new \ReflectionFunction($handler);
        if (
            $reflection->getName() === '{closure}'
            || $reflection->getClosureThis() !== null
            || $reflection->getStaticVariables() !== []
        ) {
            return null;
        }

        $calledClass = $reflection->getClosureCalledClass();
        if (!$calledClass instanceof \ReflectionClass) {
            $function = $reflection->getName();

            return \function_exists($function) ? $function : null;
        }

        $method = $reflection->getName();
        if (!$calledClass->hasMethod($method)) {
            return null;
        }
        $methodReflection = $calledClass->getMethod($method);
        if (!$methodReflection->isPublic() || !$methodReflection->isStatic()) {
            return null;
        }
        $descriptor = [$calledClass->getName(), $method];

        return \is_callable($descriptor) ? $descriptor : null;
    }
}
