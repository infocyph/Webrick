<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Interfaces\BodyStream;

/** Strong ETag helpers for native strings and explicit streams. */
final class Etag
{
    public static function fromStream(
        BodyStream $stream,
        string $salt = '',
        string $algo = 'xxh128',
        ?int $hexLen = null,
        int $chunk = 131072,
    ): ?string {
        if (!$stream->isSeekable()) {
            return null;
        }

        try {
            $position = $stream->tell();
            $stream->seek(0);
            $context = hash_init($algo);
            if ($salt !== '') {
                hash_update($context, $salt . "\n");
            }
            while (!$stream->eof()) {
                $buffer = $stream->read($chunk);
                if ($buffer === '') {
                    break;
                }
                hash_update($context, $buffer);
            }
            $hex = hash_final($context);
            $stream->seek($position);
            $digest = $hexLen === null ? $hex : substr($hex, 0, $hexLen);

            return '"' . $digest . '"';
        } catch (\Throwable) {
            return null;
        }
    }

    public static function fromString(
        string $body,
        string $salt = '',
        string $algo = 'xxh128',
        ?int $hexLen = null,
    ): string {
        $context = hash_init($algo);
        if ($salt !== '') {
            hash_update($context, $salt . "\n");
        }
        hash_update($context, $body);
        $hex = hash_final($context);
        $digest = $hexLen === null ? $hex : substr($hex, 0, $hexLen);

        return '"' . $digest . '"';
    }
}
