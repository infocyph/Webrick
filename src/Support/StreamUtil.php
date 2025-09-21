<?php

/**
 * Webrick - Stream utility functions
 * 
 * @package Infocyph\Webrick\Support
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;

/**
 * Utility class for stream-related operations
 */
final class StreamUtil
{
    /**
     * Gets the best-effort byte length of a PSR-7 stream.
     * 
     * The method attempts to determine the stream length in the following order:
     * 1. Uses the stream's getSize() method if available
     * 2. If the stream is seekable, reads remaining bytes and calculates length
     * 3. Returns the provided fallback value if neither method works
     *
     * @param Stream|BodyStream $s The stream to measure
     * @param int $fallback Fallback value to return if length cannot be determined
     * @return int The length of the stream in bytes, or fallback value
     */
    public static function byteLength(Stream|BodyStream $s, int $fallback = 0): int
    {
        $size = $s->getSize();
        if ($size !== null) {
            return $size;
        }
        if ($s->isSeekable()) {
            $pos = $s->tell();
            $data = $s->getContents(); // reads from current position
            $len = \strlen($data);
            $s->seek($pos);
            return $len;
        }
        return $fallback;
    }
}
