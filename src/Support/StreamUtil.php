<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;

final class StreamUtil
{
    /**
     * Best-effort byte length of a PSR-7 stream.
     * - uses ->getSize() if available
     * - otherwise, if seekable, reads remaining bytes and rewinds
     * - otherwise returns $fallback
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
