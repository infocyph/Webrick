<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraint;

use InvalidArgumentException;

#[\AllowDynamicProperties(false)]
/**
 * Central registry of named route segment constraints.
 *
 * Stores two kinds of validators:
 *  - PCRE-delimited regexes (used to build route patterns)
 *  - callable-strings (used to validate segment values at runtime)
 *
 * The registry ships with a set of built-in validators and allows users to
 * register additional constraints via register(). Names are case-insensitive.
 */
final class Registry
{
    // IMPORTANT: URL segments are strings → use string validators
    /**
     * Built-in callable validators.
     *
     * Values are callable-strings suitable for call_user_func or other callable resolution.
     *
     * @var array<string,callable-string>
     */
    private const array BUILTIN_CALLABLE = [
        'int' => 'ctype_digit',   // ← changed from is_int
        'digit' => 'ctype_digit',
        'numeric' => 'is_numeric',
        // If you want floats specifically, keep this callable strict or create a regex:
        'float' => 'is_numeric',
        'alpha' => 'ctype_alpha',
        'alnum' => 'ctype_alnum',
        'bool' => 'Infocyph\Webrick\Router\Constraint\Registry::isBoolString',
        'json' => 'json_validate',
    ];

    /**
     * Built-in PCRE-delimited regex validators.
     *
     * Keys are constraint names and values are full delimited PCRE strings,
     * suitable for direct use in preg_* calls.
     *
     * @var array<string,string>
     */
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

    /** @var array<string,callable-string> Mapping of constraint name => callable (function name or static method string) */
    private static array $callableValidators = self::BUILTIN_CALLABLE;
    /** @var array<string,string> Mapping of constraint name => PCRE-delimited regex */
    private static array $regexValidators = self::BUILTIN_REGEX;

    /**
     * Private constructor to prevent instantiation — Registry is static-only.
     */
    private function __construct()
    {
    }

    /**
     * Backwards-compatible accessor for regex-only users.
     *
     * Returns the inner regex fragment for the named constraint. Throws when
     * the named constraint is a callable instead of a regex.
     *
     * @param string $name Constraint name
     * @return string Inner regex fragment
     * @throws InvalidArgumentException When the named constraint is not a regex
     */
    public static function buildPattern(string $name): string
    {
        $spec = self::getValidatorSpec($name);
        if (!isset($spec['regex'])) {
            throw new InvalidArgumentException("Constraint '$name' is callable, not a regex.");
        }
        return $spec['regex'];
    }

    /**
     * Return the validator specification for a named constraint.
     *
     * The returned array has one of the forms:
     *  - ['regex' => '<inner-pattern>']  (inner, un-delimited regex suitable for embedding in routing patterns)
     *  - ['callable' => '<callable-string>'] (callable-string to be used for runtime validation)
     *
     * If the name is unknown an InvalidArgumentException is thrown.
     *
     * @param string $name Constraint name
     * @return array<string,string> Validator specification
     * @throws InvalidArgumentException When no constraint with the given name exists
     */
    public static function getValidatorSpec(string $name): array
    {
        $key = strtolower($name);

        if (isset(self::$regexValidators[$key])) {
            $rule = self::$regexValidators[$key];
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

    /**
     * Check if a string represents a boolean-like value.
     *
     * Accepts "true"/"false" (case-insensitive) and "0"/"1".
     * Useful for validating {param:bool} style constraints where segments are strings.
     *
     * @param string $s Input segment value
     * @return bool True when the string encodes a boolean
     */
    public static function isBoolString(string $s): bool
    {
        $t = strtolower($s);
        return $t === 'true' || $t === 'false' || $t === '0' || $t === '1';
    }

    /**
     * Register a new named constraint.
     *
     * The $rule may be either:
     *  - a delimited PCRE string (e.g. '/^...$/' ) which will be stored as a regex validator, OR
     *  - an existing callable-string (function name or "Class::method") which will be stored as a callable validator.
     *
     * Names are normalized to lower-case. Attempting to re-register an existing
     * name will throw InvalidArgumentException.
     *
     * @param string $name Constraint name
     * @param string $rule PCRE-delimited regex or callable-string
     * @return void
     * @throws InvalidArgumentException When the name already exists or the rule is invalid
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
            "Rule for '$name' must be a PCRE-delimited regex or an existing callable.",
        );
    }

    /*──── helpers -------------------------------------------------------*/

    /**
     * Quick heuristic to detect whether a rule string is a delimited regex.
     *
     * This checks that the first and last characters match (naive delimiter test)
     * and that the string contains a caret '^' anchor (indicating a start anchor).
     *
     * @param string $rule Candidate rule string
     * @return bool True when the string looks like a delimited PCRE regex
     */
    private static function isRegex(string $rule): bool
    {
        return $rule !== '' && $rule[0] === $rule[-1] && str_contains($rule, '^');
    }
}
