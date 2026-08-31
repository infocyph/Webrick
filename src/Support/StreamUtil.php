<?php

/**
 * Webrick - Stream utility functions
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;

/** Utility class for stream-related operations. */
final class StreamUtil
{
    /**
     * Gets the best-effort byte length of a stream without changing its final position.
     */
    public static function byteLength(Stream|BodyStream $s, int $fallback = 0): int
    {
        $size = $s->getSize();
        if ($size !== null) {
            return $size;
        }
        if (!$s->isSeekable()) {
            return $fallback;
        }

        $position = $s->tell();

        try {
            return strlen($s->getContents());
        } finally {
            $s->seek($position);
        }
    }
}
