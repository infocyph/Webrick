<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Internal;

/**
 * Tiny reusable helpers – **no** globals.
 */
final class Utils
{
    /**
     * Generate a compact, strong ETag from a string payload.
     *
     * - Produces a quoted hex token using the xxh3 hash algorithm.
     * - The returned value is the first 16 hex characters (8 bytes) of the hash,
     *   wrapped in double quotes, e.g. "\"e3b0c44298fc1c14\"".
     * - Intended as a fast, short strong ETag for caching purposes.
     *
     * @param string $payload Input string to hash
     * @return string Quoted ETag value
     */
    public static function generateEtag(string $payload): string
    {
        return '"' . substr(hash('xxh3', $payload, false), 0, 16) . '"';
    }

    /**
     * Format a UNIX epoch as an RFC-7231 HTTP-date in GMT.
     *
     * - When $epoch is null the current time() is used.
     * - The returned string is suitable for Date/Expires/Last-Modified headers,
     *   e.g. "Tue, 15 Nov 1994 08:12:31 GMT".
     *
     * @param int|null $epoch UNIX epoch seconds or null to use current time
     * @return string RFC-7231 formatted date in GMT
     */
    public static function httpDate(?int $epoch = null): string
    {
        return gmdate('D, d M Y H:i:s', $epoch ?? time()) . ' GMT';
    }
}
