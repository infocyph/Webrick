<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Support;

/**
 * Ultra-light array helpers (we don’t want a full dependency here).
 */
final class Arr
{
    private function __construct()
    {
    }

    /**
     * Ensure the value is wrapped in an array.
     *
     * @template T
     * @param  T|list<T> $value
     * @return list<T>
     */
    public static function wrap(mixed $value): array
    {
        return \is_array($value) ? $value : [$value];
    }

    /**
     * Determine if the given array is a sequential list (PHP 8.1+ has native).
     */
    public static function isList(array $array): bool
    {
        if (\function_exists('array_is_list')) {
            return array_is_list($array);
        }

        $i = 0;
        foreach ($array as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }

    /**
     * Flatten the array by **one** level.
     *
     * @param  list<array-key,mixed>|array $array
     * @return list<mixed>
     */
    public static function flatten(array $array): array
    {
        $result = [];
        foreach ($array as $value) {
            if (\is_array($value)) {
                /** @psalm-suppress MixedAssignment */
                foreach ($value as $v) {
                    $result[] = $v;
                }
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }
}
