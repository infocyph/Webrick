<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

/** Matcher-only opcodes for built-in callable route constraints. */
final class CompiledMatcherConstraintOpcode
{
    public const int ALNUM = 4;

    public const int ALPHA = 3;

    public const int BOOL = 5;

    public const int DIGIT = 1;

    public const int IPV6 = 7;

    public const int JSON = 6;

    public const int NUMERIC = 2;

    private function __construct() {}

    public static function fromCallable(string $callable): ?int
    {
        return match (strtolower(ltrim($callable, '\\'))) {
            'ctype_digit' => self::DIGIT,
            'is_numeric' => self::NUMERIC,
            'ctype_alpha' => self::ALPHA,
            'ctype_alnum' => self::ALNUM,
            'infocyph\\webrick\\router\\constraint\\registry::isboolstring' => self::BOOL,
            'json_validate' => self::JSON,
            'infocyph\\webrick\\router\\constraint\\registry::isipv6string' => self::IPV6,
            default => null,
        };
    }

    public static function isValid(int $opcode): bool
    {
        return $opcode >= self::DIGIT && $opcode <= self::IPV6;
    }

    public static function matches(int $opcode, string $value): bool
    {
        return match ($opcode) {
            self::DIGIT => ctype_digit($value),
            self::NUMERIC => is_numeric($value),
            self::ALPHA => ctype_alpha($value),
            self::ALNUM => ctype_alnum($value),
            self::BOOL => self::matchesBool($value),
            self::JSON => json_validate($value),
            self::IPV6 => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            default => false,
        };
    }

    private static function matchesBool(string $value): bool
    {
        $value = strtolower($value);

        return $value === 'true' || $value === 'false' || $value === '0' || $value === '1';
    }
}
