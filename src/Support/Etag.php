<?php

/**
 * Webrick - ETag computation helpers.
 *
 * Provides utilities for generating strong ETags from response body streams, with
 * optional salting and chunked hashing for memory efficiency.
 *
 * @package Infocyph\Webrick\Support
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Interfaces\BodyStream;

/**
 * Compute strong ETags from streams.
 *
 * This final utility class offers a chunked hashing method that preserves the
 * original stream position, supports optional salt (e.g., normalized query),
 * and returns a quoted hexadecimal digest truncated to a requested length.
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
     * - Returns a quoted hexadecimal digest (first $hexLen chars of the $algo digest),
     *   or null on failure (any thrown error/exception is caught).
     *
     * @param BodyStream $stream Seekable stream to hash (position is preserved).
     * @param string     $salt   Optional salt to include before the content (e.g., normalized query).
     * @param string     $algo   Hash algorithm name (e.g., 'xxh3', 'sha256'); must be supported by hash_init().
     * @param int        $hexLen Number of hex characters to include in the final (quoted) ETag.
     * @param int        $chunk  Chunk size in bytes used when reading the stream.
     *
     * @return string|null Quoted hex digest (truncated) on success; null on error or if not seekable.
     */
    public static function fromStream(
        BodyStream $stream,
        string $salt = '',
        string $algo = 'xxh3',
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
