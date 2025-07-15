<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraint;

use InvalidArgumentException;

final class Registry
{
    /**
     * @var array<string,string>  name => PCRE-delimited regex
     */
    private static array $regexValidators = [
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

    /**
     * @var array<string,string>  name => callable name
     */
    private static array $callableValidators = [
        'int' => 'is_int',
        'float' => 'is_float',
        'numeric' => 'is_numeric',
        'alpha' => 'ctype_alpha',
        'alnum' => 'ctype_alnum',
        'digit' => 'ctype_digit',
        'bool' => 'is_bool',
        'json' => 'json_validate',
    ];

    /**
     * Add a new constraint.
     *
     * @param non-empty-string $name Lowercase identifier
     * @param string $rule Either a PCRE-delimited regex or a callable name
     *
     * @throws InvalidArgumentException
     */
    public static function register(string $name, string $rule): void
    {
        $key = strtolower($name);

        if (isset(self::$regexValidators[$key], self::$callableValidators[$key])) {
            throw new InvalidArgumentException("Constraint '$name' already exists.");
        }

        /* ── regex rule ─────────────────────────────────────────────────── */
        if (self::isRegex($rule)) {
            if (@preg_match($rule, '') === false) {          // invalid PCRE
                throw new InvalidArgumentException("Invalid PCRE for constraint '$name'.");
            }
            self::$regexValidators[$key] = $rule;
            return;
        }

        /* ── callable rule ──────────────────────────────────────────────── */
        if (\is_callable($rule)) {
            self::$callableValidators[$key] = $rule;
            return;
        }

        throw new InvalidArgumentException(
            "Rule for '$name' must be a PCRE-delimited regex or an existing callable name."
        );
    }

    /**
     * Check if a given value matches a named constraint.
     *
     * @param string $name Lowercase identifier
     * @param string $segment Value to check
     *
     * @return bool True if the value matches the constraint, false otherwise.
     */
    public static function check(string $name, string $segment): bool
    {
        $key = strtolower($name);

        if (isset(self::$regexValidators[$key])) {
            return (bool)preg_match(self::$regexValidators[$key], $segment);
        }

        if (isset(self::$callableValidators[$key])) {
            return (bool)call_user_func(self::$callableValidators[$key], $segment);
        }

        return false;
    }


    /**
     * Get the regex pattern (sans delimiters) for the given named constraint.
     *
     * @param non-empty-string $name
     * @return non-empty-string
     * @throws InvalidArgumentException if no regex constraint named $name exists
     */
    public static function buildPattern(string $name): string
    {
        $key = strtolower($name);

        if (!isset(self::$regexValidators[$key])) {
            throw new InvalidArgumentException("No regex constraint named '$name'.");
        }

        $rule = self::$regexValidators[$key];
        $delim = $rule[0];
        $body = trim($rule, $delim);
        $body = ltrim($body, '^');
        $body = rtrim($body, '$');

        return $body === '' ? '[^/]+' : $body;
    }

    private static function isRegex(string $rule): bool
    {
        // silence E_WARNING on malformed patterns and avoid touching preg_last_error()
        return @preg_match('/^(.)((?:\\\1|[^\1])*)\1[imsxuADSUXJ]*$/', $rule) === 1;
    }

    /**
     * Prevent instantiation.
     *
     * This class is a static utility; no instances are allowed.
     */
    private function __construct()
    {
    }
}
