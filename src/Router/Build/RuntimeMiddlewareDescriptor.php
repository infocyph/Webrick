<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use InvalidArgumentException;

/**
 * Artifact-safe middleware resolver plus deterministic runtime parameters.
 *
 * The resolver is intentionally not executed in the build plane. Compiled
 * runtimes resolve it inside the active request scope and then invoke the
 * resulting middleware with Request/next.
 */
final readonly class RuntimeMiddlewareDescriptor
{
    /**
     * @param string|array{0:string,1:string}|callable $resolver
     * @param array<int|string,mixed> $parameters
     */
    public function __construct(
        public mixed $resolver,
        public array $parameters = [],
    ) {
        self::assertResolver($resolver);
        self::assertExportable($parameters, 'parameters');
    }

    private static function assertExportable(mixed $value, string $path): void
    {
        if ($value === null || is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertExportable($item, $path . '[' . (string) $key . ']');
            }

            return;
        }

        throw new InvalidArgumentException(
            "Runtime middleware {$path} must contain only scalar, null, or recursively exportable array values.",
        );
    }

    private static function assertResolver(mixed $resolver): void
    {
        if (is_string($resolver) && trim($resolver) !== '') {
            return;
        }
        if (
            is_array($resolver)
            && array_is_list($resolver)
            && count($resolver) === 2
            && isset($resolver[0], $resolver[1])
            && is_string($resolver[0])
            && trim($resolver[0]) !== ''
            && is_string($resolver[1])
            && trim($resolver[1]) !== ''
        ) {
            return;
        }
        if (is_callable($resolver)) {
            return;
        }

        throw new InvalidArgumentException(
            'Runtime middleware resolver must be a non-empty callable/class/function descriptor.',
        );
    }
}
