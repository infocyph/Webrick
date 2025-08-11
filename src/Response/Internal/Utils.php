<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Internal;

use Infocyph\Webrick\Request\Core\Stream;

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

    /**
     * Strong, short ETag from a **seekable** stream without buffering the whole body.
     * Adds an optional salt (e.g. canonical query string) the same way your old code did.
     */
    public static function etagFromStream(Stream $body, string $salt = ''): string
    {
        $ctx = hash_init('sha1');

        // remember position and rewind
        $pos = $body->isSeekable() ? $body->tell() : null;
        if ($body->isSeekable()) {
            $body->rewind();
        }

        // 64 KiB chunks
        while (!$body->eof()) {
            hash_update($ctx, $body->read(65536));
        }

        if ($salt !== '') {
            hash_update($ctx, '#' . $salt);
        }

        $hex = hash_final($ctx);

        // restore cursor
        if ($pos !== null) {
            $body->seek($pos);
        }

        return '"' . substr($hex, 0, 16) . '"';
    }
}
