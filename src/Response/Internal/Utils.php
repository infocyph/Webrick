<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Internal;

/**
 * Tiny reusable helpers – **no** globals.
 */
final class Utils
{
    /** RFC-7231 HTTP-date. */
    public static function httpDate(?int $epoch = null): string
    {
        return gmdate('D, d M Y H:i:s', $epoch ?? time()) . ' GMT';
    }

    /** Strong, short ETag: `"sha1-8-bytes"` from a string payload. */
    public static function generateEtag(string $payload): string
    {
        return '"' . substr(sha1($payload, false), 0, 16) . '"';
    }
}
