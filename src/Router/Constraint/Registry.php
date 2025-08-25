<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraint;

use InvalidArgumentException;

#[\AllowDynamicProperties(false)]
final class Registry
{
    /** @var array<string,string> */
    private static array $regexValidators = self::BUILTIN_REGEX;

    /** @var array<string,callable-string> */
    private static array $callableValidators = self::BUILTIN_CALLABLE;

    private const array BUILTIN_REGEX = [
        // — UUIDs & IDs —
        'uuid'      => '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-9][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        'ulid'      => '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/',
        'cuid'      => '/^c[0-9a-z]{8,}$/i',
        // — Formats —
        'slug'      => '/^[A-Za-z0-9_-]+$/',
        'email'     => '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/',
        'hex'       => '/^[A-Fa-f0-9]+$/',
        'hexcolor'  => '/^#?([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
        'base64'    => '/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/',
        'semver'    => '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[\w\.-]+)?(?:\+[\w\.-]+)?$/',
        // — Date & time —
        'date'      => '/^\d{4}-\d{2}-\d{2}$/',
        'time'      => '/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
        'datetime'  => '/^\d{4}-\d{2}-\d{2}[ T](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?(?:\.\d+)?(?:Z|[+\-][01]\d:[0-5]\d)?$/i',
        // — Networking —
        'ipv4'      => '/^(25[0-5]|2[0-4]\d|[01]?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d?\d)){3}$/',
        'ipv6'      => '/^([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}$/',
        'ipv4_cidr' => '/^(?:25[0-5]|2[0-4]\d|[01]?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d?\d)){3}\/(?:[0-9]|[12]\d|3[0-2])$/',
        'mac'       => '/^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/',
    ];

    // IMPORTANT: URL segments are strings → use string validators
    private const array BUILTIN_CALLABLE = [
        'int'     => 'ctype_digit',   // ← changed from is_int
        'digit'   => 'ctype_digit',
        'numeric' => 'is_numeric',
        // If you want floats specifically, keep this callable strict or create a regex:
        'float'   => 'is_numeric',
        'alpha'   => 'ctype_alpha',
        'alnum'   => 'ctype_alnum',
        'bool'    => 'Infocyph\Webrick\Router\Constraint\Registry::isBoolString',
        'json'    => 'json_validate',
    ];

    /** Allow simple “true/false/0/1” for {x:bool} */
    public static function isBoolString(string $s): bool
    {
        $t = strtolower($s);
        return $t === 'true' || $t === 'false' || $t === '0' || $t === '1';
    }

    /**
     * Register either a PCRE (delimited) or a callable-string.
     */
    public static function register(string $name, string $rule): void
    {
        $key = strtolower($name);

        if (isset(self::$regexValidators[$key]) || isset(self::$callableValidators[$key])) {
            throw new InvalidArgumentException("Constraint '$name' already exists.");
        }

        if (self::isRegex($rule)) {
            if (@preg_match($rule, '') === false) {
                throw new InvalidArgumentException("Invalid PCRE for constraint '$name'.");
            }
            self::$regexValidators[$key] = $rule;
            return;
        }

        if (\is_callable($rule)) {
            /** @var callable-string $rule */
            self::$callableValidators[$key] = $rule;
            return;
        }

        throw new InvalidArgumentException(
            "Rule for '$name' must be a PCRE-delimited regex or an existing callable."
        );
    }

    /**
     * Return validator spec for a constraint name.
     *  - ['regex' => '...inner...'] OR
     *  - ['callable' => 'function_name']
     */
    public static function getValidatorSpec(string $name): array
    {
        $key = strtolower($name);

        if (isset(self::$regexValidators[$key])) {
            $rule  = self::$regexValidators[$key];
            $delim = $rule[0];
            $inner = trim($rule, $delim);
            $inner = ltrim(rtrim($inner, '$'), '^') ?: '[^/]+';
            return ['regex' => $inner];
        }

        if (isset(self::$callableValidators[$key])) {
            return ['callable' => self::$callableValidators[$key]];
        }

        throw new InvalidArgumentException("No constraint named '$name'.");
    }

    /** Back-compat: still available for regex-only users. */
    public static function buildPattern(string $name): string
    {
        $spec = self::getValidatorSpec($name);
        if (!isset($spec['regex'])) {
            throw new InvalidArgumentException("Constraint '$name' is callable, not a regex.");
        }
        return $spec['regex'];
    }

    /*──── helpers -------------------------------------------------------*/

    private static function isRegex(string $rule): bool
    {
        return $rule !== '' && $rule[0] === $rule[-1] && str_contains($rule, '^');
    }

    private function __construct()
    {
    }
}
