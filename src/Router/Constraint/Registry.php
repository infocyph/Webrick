<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraint;

use InvalidArgumentException;
use LogicException;

/** Named route-constraint registry, mutable only during build/bootstrap. */
final class Registry
{
    /** @var array<string,callable-string> */
    private const array BUILTIN_CALLABLE = [
        'int' => 'ctype_digit',
        'digit' => 'ctype_digit',
        'numeric' => 'is_numeric',
        'float' => 'is_numeric',
        'alpha' => 'ctype_alpha',
        'alnum' => 'ctype_alnum',
        'bool' => 'Infocyph\\Webrick\\Router\\Constraint\\Registry::isBoolString',
        'json' => 'json_validate',
        'ipv6' => 'Infocyph\\Webrick\\Router\\Constraint\\Registry::isIpv6String',
    ];

    /** @var array<string,string> */
    private const array BUILTIN_REGEX = [
        'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-9][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        'ulid' => '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/',
        'cuid' => '/^c[0-9a-z]{8,}$/i',
        'slug' => '/^[A-Za-z0-9_-]+$/',
        'email' => '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}$/',
        'hex' => '/^[A-Fa-f0-9]+$/',
        'hexcolor' => '/^#?([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
        'base64' => '/^(?:[A-Za-z0-9+\\/]{4})*(?:[A-Za-z0-9+\\/]{2}==|[A-Za-z0-9+\\/]{3}=)?$/',
        'semver' => '/^(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)(?:-[\\w\\.-]+)?(?:\\+[\\w\\.-]+)?$/',
        'date' => '/^\\d{4}-\\d{2}-\\d{2}$/',
        'time' => '/^([01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/',
        'datetime' => '/^\\d{4}-\\d{2}-\\d{2}[ T](?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?(?:\\.\\d+)?(?:Z|[+\\-][01]\\d:[0-5]\\d)?$/i',
        'ipv4' => '/^(25[0-5]|2[0-4]\\d|[01]?\\d?\\d)(?:\\.(?:25[0-5]|2[0-4]\\d|[01]?\\d?\\d)){3}$/',
        'ipv4_cidr' => '/^(?:25[0-5]|2[0-4]\\d|[01]?\\d?\\d)(?:\\.(?:25[0-5]|2[0-4]\\d|[01]?\\d?\\d)){3}\\/(?:[0-9]|[12]\\d|3[0-2])$/',
        'mac' => '/^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/',
    ];

    /** @var array<string,callable-string> */
    private static array $callableValidators = self::BUILTIN_CALLABLE;

    private static bool $frozen = false;

    /** @var array<string,string> */
    private static array $regexValidators = self::BUILTIN_REGEX;

    private function __construct() {}

    public static function buildPattern(string $name): string
    {
        $spec = self::getValidatorSpec($name);
        if (!isset($spec['regex'])) {
            throw new InvalidArgumentException("Constraint '$name' is callable, not a regex.");
        }

        return $spec['regex'];
    }

    public static function freeze(): void
    {
        self::$frozen = true;
    }

    public static function frozen(): bool
    {
        return self::$frozen;
    }

    /** @return array<string,string> */
    public static function getValidatorSpec(string $name): array
    {
        $key = strtolower($name);
        if (isset(self::$regexValidators[$key])) {
            return ['regex' => self::regexInner(self::$regexValidators[$key])];
        }
        if (isset(self::$callableValidators[$key])) {
            return ['callable' => self::$callableValidators[$key]];
        }

        throw new InvalidArgumentException("No constraint named '$name'.");
    }

    public static function isBoolString(string $value): bool
    {
        $value = strtolower($value);

        return $value === 'true' || $value === 'false' || $value === '0' || $value === '1';
    }

    public static function isIpv6String(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    public static function register(string $name, string $rule): void
    {
        if (self::$frozen) {
            throw new LogicException('Route constraint registry is frozen for production runtime.');
        }

        $key = strtolower($name);
        if (isset(self::$regexValidators[$key]) || isset(self::$callableValidators[$key])) {
            throw new InvalidArgumentException("Constraint '$name' already exists.");
        }
        if (self::isRegex($rule)) {
            if (preg_match($rule, '') === false) {
                throw new InvalidArgumentException("Invalid PCRE for constraint '$name'.");
            }
            self::$regexValidators[$key] = $rule;

            return;
        }
        if (is_callable($rule)) {
            self::$callableValidators[$key] = $rule;

            return;
        }

        throw new InvalidArgumentException("Rule for '$name' must be a PCRE-delimited regex or an existing callable.");
    }

    private static function isRegex(string $rule): bool
    {
        return self::splitDelimitedRegex($rule) !== null;
    }

    private static function regexInner(string $rule): string
    {
        $parts = self::splitDelimitedRegex($rule);
        if ($parts === null) {
            throw new InvalidArgumentException("Invalid regex rule '{$rule}'.");
        }
        [$inner, $modifiers] = $parts;
        if (str_starts_with($inner, '^')) {
            $inner = substr($inner, 1);
        }
        if (str_ends_with($inner, '$') && !str_ends_with($inner, '\\$')) {
            $inner = substr($inner, 0, -1);
        }
        if ($inner === '') {
            $inner = '[^/]+';
        }

        return $modifiers === '' ? $inner : '(?' . $modifiers . ':' . $inner . ')';
    }

    /** @return array{0:string,1:string}|null */
    private static function splitDelimitedRegex(string $rule): ?array
    {
        $length = strlen($rule);
        if ($length < 3) {
            return null;
        }
        $delimiter = $rule[0];
        if (ctype_alnum($delimiter) || ctype_space($delimiter) || $delimiter === '\\') {
            return null;
        }

        $closing = match ($delimiter) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $delimiter,
        };
        $paired = $closing !== $delimiter;
        $depth = 0;
        $end = null;
        $escaped = false;

        for ($i = 1; $i < $length; $i++) {
            $char = $rule[$i];
            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($char === '\\') {
                $escaped = true;

                continue;
            }
            if ($paired && $char === $delimiter) {
                $depth++;

                continue;
            }
            if ($char !== $closing) {
                continue;
            }
            if ($paired && $depth > 0) {
                $depth--;

                continue;
            }
            $end = $i;

            break;
        }
        if ($end === null || $end === 1) {
            return null;
        }

        $modifiers = substr($rule, $end + 1);
        if ($modifiers !== '' && preg_match('/^[A-Za-z]+$/', $modifiers) !== 1) {
            return null;
        }

        return [substr($rule, 1, $end - 1), $modifiers];
    }
}
