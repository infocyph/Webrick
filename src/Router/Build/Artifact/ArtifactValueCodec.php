<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build\Artifact;

use Closure;
use Infocyph\InterMix\Serializer\ClosureSerializer;
use ReflectionFunction;
use ReflectionMethod;
use UnexpectedValueException;

/** Build/runtime-boundary codec for already-compiled execution descriptors. */
final class ArtifactValueCodec
{
    private const string CLOSURE = 'closure';

    private const string VALUE = 'value';

    private function __construct() {}

    public static function decode(mixed $payload): mixed
    {
        if (!is_array($payload) || !is_string($payload['kind'] ?? null)) {
            throw new UnexpectedValueException('Invalid Webrick artifact value descriptor.');
        }

        if ($payload['kind'] === self::VALUE) {
            return self::decodeValue($payload['value'] ?? null);
        }
        if ($payload['kind'] !== self::CLOSURE || !is_string($payload['value'] ?? null)) {
            throw new UnexpectedValueException('Unknown Webrick artifact value descriptor.');
        }

        try {
            return ClosureSerializer::unserialize($payload['value']);
        } catch (\Throwable $exception) {
            throw new UnexpectedValueException('Unable to restore Webrick Closure artifact.', 0, $exception);
        }
    }

    /** @return array{kind:string,value:mixed} */
    public static function encode(mixed $value): array
    {
        if (is_string($value)) {
            return ['kind' => self::VALUE, 'value' => $value];
        }

        if (
            is_array($value)
            && array_is_list($value)
            && count($value) === 2
            && isset($value[0], $value[1])
            && is_string($value[0])
            && is_string($value[1])
        ) {
            return ['kind' => self::VALUE, 'value' => [$value[0], $value[1]]];
        }

        if ($value instanceof Closure) {
            $descriptor = self::staticMethodDescriptor($value);
            if ($descriptor !== null) {
                return ['kind' => self::VALUE, 'value' => $descriptor];
            }
        } elseif (is_callable($value)) {
            $value = Closure::fromCallable($value);
        }

        if (!$value instanceof Closure) {
            throw new UnexpectedValueException(
                'Compiled artifact values must be scalar callable descriptors or Closures.',
            );
        }

        return ['kind' => self::CLOSURE, 'value' => ClosureSerializer::serialize($value)];
    }

    /** @return array{0:string,1:string}|string */
    private static function decodeValue(mixed $value): array|string
    {
        if (is_string($value)) {
            return $value;
        }
        if (
            is_array($value)
            && array_is_list($value)
            && count($value) === 2
            && isset($value[0], $value[1])
            && is_string($value[0])
            && is_string($value[1])
        ) {
            return [$value[0], $value[1]];
        }

        throw new UnexpectedValueException('Invalid scalar Webrick artifact value.');
    }

    /** @return array{0:class-string,1:string}|null */
    private static function staticMethodDescriptor(Closure $closure): ?array
    {
        $reflection = new ReflectionFunction($closure);
        $calledClass = $reflection->getClosureCalledClass();
        $method = $reflection->getName();

        if (
            $calledClass === null
            || $reflection->getClosureThis() !== null
            || $method === '{closure}'
            || $reflection->getStaticVariables() !== []
            || !method_exists($calledClass->getName(), $method)
        ) {
            return null;
        }

        $methodReflection = new ReflectionMethod($calledClass->getName(), $method);
        if (!$methodReflection->isPublic() || !$methodReflection->isStatic()) {
            return null;
        }

        /** @var class-string $class */
        $class = $calledClass->getName();

        return [$class, $method];
    }
}
