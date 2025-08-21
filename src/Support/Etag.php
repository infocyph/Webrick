<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Request\Core\Stream;

final class Etag
{
    /**
     * Strong ETag from a stream (chunked), optionally salted (e.g., normalized query).
     * Returns a quoted hex (first $hexLen chars of $algo digest) or null on failure.
     */
    public static function fromStream(
        Stream $stream,
        string $salt = '',
        string $algo = 'sha1',
        int $hexLen = 16,
        int $chunk = 131072, // 128 KiB
    ): ?string {
        if (!$stream->isSeekable()) {
            return null;
        }
        try {
            $pos = $stream->tell();
            $stream->seek(0);

            $ctx = hash_init($algo);
            if ($salt !== '') {
                hash_update($ctx, $salt . "\n");
            }
            while (!$stream->eof()) {
                $buf = $stream->read($chunk);
                if ($buf === '') {
                    break;
                }
                hash_update($ctx, $buf);
            }
            $hex = hash_final($ctx);

            $stream->seek($pos);
            return '"' . substr($hex, 0, $hexLen) . '"';
        } catch (\Throwable) {
            return null;
        }
    }
}
