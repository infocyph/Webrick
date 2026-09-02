<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build\Artifact;

use Closure;
use Infocyph\InterMix\Serializer\ClosureSerializer;
use Infocyph\Webrick\Router\Dispatch\RuntimeMiddlewareDescriptor;
use ReflectionFunction;
use ReflectionMethod;
use UnexpectedValueException;

/** Build/runtime-boundary codec for already-compiled execution descriptors. */
final class ArtifactValueCodec
{
    private const string CALLABLE = 'callable';

    private const string CLOSURE = 'closure';

    private const string RUNTIME_MIDDLEWARE = 'runtime_middleware';

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
        if ($payload['kind'] === self::RUNTIME_MIDDLEWARE) {
            $resolver = $payload['resolver'] ?? null;
            $parameters = $payload['parameters'] ?? null;
            if (!is_array($resolver) || !is_array($parameters) || !array_is_list($parameters) || !array_all($parameters, is_string(...))) {
                throw new UnexpectedValueException('Invalid runtime middleware artifact descriptor.');
            }

            return new RuntimeMiddlewareDescriptor(self::decode($resolver), $parameters);
        }
        if (!is_string($payload['value'] ?? null)) {
            throw new UnexpectedValueException('Invalid Webrick artifact value payload.');
        }
        if ($payload['kind'] === self::CALLABLE) {
            $transport = self::decodeClosure($payload['value']);
            $callable = $transport();
            if (!is_callable($callable)) {
                throw new UnexpectedValueException('Webrick callable artifact did not restore a callable.');
            }

            return $callable;
        }
        if ($payload['kind'] !== self::CLOSURE) {
            throw new UnexpectedValueException('Unknown Webrick artifact value descriptor.');
        }

        return self::decodeClosure($payload['value']);
    }

    /**
     * @return array<string,mixed>
     */
    public static function encode(mixed $value): array
    {
        if ($value instanceof RuntimeMiddlewareDescriptor) {
            return [
                'kind' => self::RUNTIME_MIDDLEWARE,
                'resolver' => self::encode($value->resolverSpec()),
                'parameters' => $value->parameters,
            ];
        }

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
            return self::encodeClosure($value);
        }

        if (is_callable($value)) {
            return self::encodeCallable($value);
        }

        throw new UnexpectedValueException(
            'Compiled artifact values must be scalar callable descriptors, runtime middleware descriptors or Closures.',
        );
    }

    /**
     * @return array{0:object,1:string}|null
     */
    private static function boundMethodDescriptor(Closure $closure): ?array
    {
        $reflection = new ReflectionFunction($closure);
        $object = $reflection->getClosureThis();
        $method = $reflection->getName();

        if (
            $object === null
            || $method === '{closure}'
            || !method_exists($object, $method)
        ) {
            return null;
        }

        $methodReflection = new ReflectionMethod($object, $method);
        if (!$methodReflection->isPublic() || $methodReflection->isStatic()) {
            return null;
        }

        return [$object, $method];
    }

    private static function decodeClosure(string $payload): Closure
    {
        try {
            return ClosureSerializer::unserialize($payload);
        } catch (\Throwable $exception) {
            throw new UnexpectedValueException('Unable to restore Webrick Closure artifact.', 0, $exception);
        }
    }

    /**
     * @return array{0:string,1:string}|string
     */
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

    /**
     * @return array{kind:string,value:string}
     */
    private static function encodeCallable(callable $callable): array
    {
        $transport = static fn(): callable => $callable;

        return ['kind' => self::CALLABLE, 'value' => ClosureSerializer::serialize($transport)];
    }

    /** @return array{kind:string,value:mixed} */
    private static function encodeClosure(Closure $closure): array
    {
        $staticDescriptor = self::staticMethodDescriptor($closure);
        if ($staticDescriptor !== null) {
            return ['kind' => self::VALUE, 'value' => $staticDescriptor];
        }

        $boundDescriptor = self::boundMethodDescriptor($closure);
        if ($boundDescriptor !== null && is_callable($boundDescriptor)) {
            return self::encodeCallable($boundDescriptor);
        }

        return ['kind' => self::CLOSURE, 'value' => ClosureSerializer::serialize($closure)];
    }

    /**
     * @return array{0:class-string,1:string}|null
     */
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
