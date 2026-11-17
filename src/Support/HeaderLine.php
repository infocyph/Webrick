<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use InvalidArgumentException;

/** Validate a response-header line at the actual SAPI emission boundary. */
final readonly class HeaderLine
{
    private const string NAME_CHARS = "!#$%&'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";

    // Header field values permit HTAB, SP, visible ASCII and obs-text,
    // but never other C0 control characters or DEL.
    private const string INVALID_VALUE_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    private function __construct() {}

    /**
     * The token grammar and C0/DEL check prevent invalid HTTP header lines.
     * Valid names and values are passed through unchanged.
     *
     * @throws \InvalidArgumentException
     */
    public static function validated(string $name, string $value): string
    {
        if (
            $name === ''
            || strspn($name, self::NAME_CHARS) !== strlen($name)
            || strcspn($value, self::INVALID_VALUE_BYTES) !== strlen($value)
        ) {
            throw new InvalidArgumentException('Invalid HTTP response header name or value.');
        }

        return "{$name}: {$value}";
    }
}
