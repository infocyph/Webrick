<?php

/*-------------------------------------------------------------------------*
 *  Constraint\Registry – PHP 8.4 tuned                                    *
 *-------------------------------------------------------------------------*/
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraint;

use InvalidArgumentException;

#[\AllowDynamicProperties(false)]
final class Registry
{
    /*──── static cache --------------------------------------------------*/

    /** @var array<string,string> */
    private static array $regexValidators = self::BUILTIN_REGEX;
    /** @var array<string,string|callable-string> */
    private static array $callableValidators = self::BUILTIN_CALLABLE;

    /*──── immutable built-ins (const => OPcache-friendly) --------------*/

    private const array BUILTIN_REGEX = [
        // — UUIDs & IDs —
        'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-9][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        'ulid' => '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/',
        'cuid' => '/^c[0-9a-z]{8,}$/i',
        // — Formats —
        'slug' => '/^[A-Za-z0-9_-]+$/',
        'email' => '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/',
        'hex' => '/^[A-Fa-f0-9]+$/',
        'hexcolor' => '/^#?([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
        'base64' => '/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/',
        'semver' => '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[\w\.-]+)?(?:\+[\w\.-]+)?$/',
        // — Date & time —
        'date' => '/^\d{4}-\d{2}-\d{2}$/',
        'time' => '/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
        'datetime' => '/^\d{4}-\d{2}-\d{2}[ T](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?(?:\.\d+)?(?:Z|[+\-][01]\d:[0-5]\d)?$/i',
        // — Networking —
        'ipv4' => '/^(25[0-5]|2[0-4]\d|[01]?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d?\d)){3}$/',
        'ipv6' => '/^([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}$/',
        'ipv4_cidr' => '/^(?:25[0-5]|2[0-4]\d|[01]?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d?\d)){3}\/(?:[0-9]|[12]\d|3[0-2])$/',
        'mac' => '/^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/',
    ];

    private const array BUILTIN_CALLABLE = [
        'int' => 'is_int',
        'float' => 'is_float',
        'numeric' => 'is_numeric',
        'alpha' => 'ctype_alpha',
        'alnum' => 'ctype_alnum',
        'digit' => 'ctype_digit',
        'bool' => 'is_bool',
        'json' => 'json_validate',
    ];

    /*──── user-land extension API --------------------------------------*/

    /**
     * @throws InvalidArgumentException
     */
    public static function register(string $name, string $rule): void
    {
        $key = strtolower($name);

        if (isset(self::$regexValidators[$key]) || isset(self::$callableValidators[$key])) {
            throw new InvalidArgumentException("Constraint '$name' already exists.");
        }

        /* fast path – PCRE rule? */
        if (self::isRegex($rule)) {
            if (@preg_match($rule, '') === false) {
                throw new InvalidArgumentException("Invalid PCRE for constraint '$name'.");
            }
            self::$regexValidators[$key] = $rule;
            return;
        }

        /* callable rule */
        if (\is_callable($rule)) {
            self::$callableValidators[$key] = $rule;
            return;
        }

        throw new InvalidArgumentException(
            "Rule for '$name' must be a PCRE-delimited regex or an existing callable.",
        );
    }

    public static function check(string $name, string $segment): bool
    {
        $key = strtolower($name);

        return isset(self::$regexValidators[$key])
            ? (bool)preg_match(self::$regexValidators[$key], $segment)
            : (isset(self::$callableValidators[$key]) &&
                \call_user_func(self::$callableValidators[$key], $segment));
    }

    /**
     * Turn a named regex constraint into the **inner** PCRE body.
     *
     * @throws InvalidArgumentException
     */
    public static function buildPattern(string $name): string
    {
        $key = strtolower($name);
        if (!isset(self::$regexValidators[$key])) {
            throw new InvalidArgumentException("No regex constraint named '$name'.");
        }

        // strip delimiters + anchors once, JIT-friendly
        $rule = self::$regexValidators[$key];
        $delim = $rule[0];
        $rule = trim($rule, $delim);
        return ltrim(rtrim($rule, '$'), '^') ?: '[^/]+';
    }

    /*──── helpers -------------------------------------------------------*/

    private static function isRegex(string $rule): bool
    {
        // cheaper than `preg_last_error()` and never mutates global regex state
        return $rule !== '' && $rule[0] === $rule[-1] && str_contains($rule, '^');
    }

    private function __construct() {}
}
