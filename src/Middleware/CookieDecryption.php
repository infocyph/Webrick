<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;

/** Groups and decrypts segmented incoming cookies. */
final class CookieDecryption
{
    /**
     * @param array<string,mixed> $cookies
     * @param Closure(string,string):mixed $decrypt
     * @return array<string,mixed>
     */
    public static function all(array $cookies, string $prefix, bool $dropFailures, Closure $decrypt): array
    {
        [$result, $assemblies] = self::partition($cookies, $prefix);
        foreach ($assemblies as $name => $parts) {
            self::decryptAssembly($result, $name, $parts, $dropFailures, $decrypt);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<int,string> $parts
     * @param Closure(string,string):mixed $decrypt
     */
    private static function decryptAssembly(array &$result, string $name, array $parts, bool $dropFailures, Closure $decrypt): void
    {
        ksort($parts);
        if (array_keys($parts) !== range(1, count($parts))) {
            if (!$dropFailures) {
                $result[$name] = null;
            }

            return;
        }
        $plain = $decrypt($name, implode('', $parts));
        if (($plain === null || $plain === false) && $dropFailures) {
            return;
        }
        $result[$name] = $plain === false ? null : $plain;
    }

    /**
     * @param array<string,mixed> $cookies
     * @return array{array<string,mixed>,array<string,array<int,string>>}
     */
    private static function partition(array $cookies, string $prefix): array
    {
        $result = [];
        $assemblies = [];
        $pattern = '/^(' . preg_quote($prefix, '/') . '[^.]+)(?:\.p(\d+))?$/';
        foreach ($cookies as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (preg_match($pattern, $name, $matches) !== 1) {
                $result[$name] = $value;

                continue;
            }
            $assemblies[$matches[1]][(int) ($matches[2] ?? 1)] = $value;
        }

        return [$result, $assemblies];
    }
}
