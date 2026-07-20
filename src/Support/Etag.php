<?php

/**
 * Webrick - ETag computation helpers.
 *
 * Provides utilities for generating strong ETags from response body streams, with
 * optional salting and chunked hashing for memory efficiency.
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Interfaces\BodyStream;

/**
 * Compute strong ETags from streams.
 *
 * This final utility class offers a chunked hashing method that preserves the
 * original stream position, supports optional salt (e.g., normalized query),
 * and returns a quoted hexadecimal digest.
 */
final class Etag
{
    /**
     * Generate a strong ETag from a seekable stream.
     *
     * The method:
     * - Requires a seekable stream; returns null otherwise.
     * - Saves and restores the stream position to avoid side effects.
     * - Computes a hash in chunks to reduce memory usage.
     * - Optionally prefixes the hash input with a salt followed by a newline.
     * - Returns a quoted hexadecimal digest (optionally truncated to $hexLen characters),
     *   or null on failure (any thrown error/exception is caught).
     *
     * @param BodyStream $stream Seekable stream to hash (position is preserved).
     * @param string $salt Optional salt to include before the content (e.g., normalized query).
     * @param string $algo Cryptographic hash algorithm supported by hash_init().
     * @param int|null $hexLen Optional number of hex characters to include; null retains the full digest.
     * @param int $chunk Chunk size in bytes used when reading the stream.
     * @return string|null Quoted hex digest (optionally truncated) on success; null on error or if not seekable.
     */
    public static function fromStream(
        BodyStream $stream,
        string $salt = '',
        string $algo = 'xxh128',
        ?int $hexLen = null,
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

            $digest = $hexLen === null ? $hex : substr($hex, 0, $hexLen);

            return '"' . $digest . '"';
        } catch (\Throwable) {
            return null;
        }
    }
}
