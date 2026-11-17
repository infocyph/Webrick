<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use InvalidArgumentException;

/** Validate a response-header line at the actual SAPI emission boundary. */
final readonly class HeaderLine
{
    private const string NAME_CHARS = "!#$%&'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";

    private function __construct() {}

    /**
     * The token grammar and CR/LF/NUL check prevent HTTP response splitting.
     * Valid names and values are passed through unchanged.
     *
     * @throws \InvalidArgumentException
     */
    public static function validated(string $name, string $value): string
    {
        if (
            $name === ''
            || strspn($name, self::NAME_CHARS) !== strlen($name)
            || strcspn($value, "\r\n\0") !== strlen($value)
        ) {
            throw new InvalidArgumentException('Invalid HTTP response header name or value.');
        }

        return "{$name}: {$value}";
    }
}
