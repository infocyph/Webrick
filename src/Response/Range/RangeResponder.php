<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Headers\Range as SimpleRange;
use Infocyph\Webrick\Response\Internal\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Builds **206 Partial Content** (or 200 / 416) responses for seekable sources.
 *
 * ▸ Automatically attaches **ETag**, **Last-Modified**, sensible
 *   `Cache-Control: public, max-age=31536000, immutable`, and
 *   `Accept-Ranges: bytes` for static files.
 * ▸ Plays nicely with ConditionalMiddleware (supports validators).
 */
final class RangeResponder
{
    /* --------------------------------------------------------------
     |  Public factory
     |-------------------------------------------------------------- */

    public static function forFile(
        \Psr\Http\Message\ServerRequestInterface $req,
        string                                   $absolutePath,
        string                                   $mediaType = 'application/octet-stream',
        array                                    $headers   = []
    ): Response {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return new Response(404);
        }

        $stat   = stat($absolutePath);
        $len    = (int) $stat['size'];
        $mtime  = (int) $stat['mtime'];
        $etag   = '"' . dechex($len) . '-' . dechex($mtime) . '"';

        /* ---- default caching headers ---------------------------- */
        $defaults = [
            'Accept-Ranges' => 'bytes',
            'ETag'          => $etag,
            'Last-Modified' => Utils::httpDate($mtime),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];
        $headers += $defaults;             // caller can override per key

        /* ---- Range parsing -------------------------------------- */
        $range = RangeParser::parse($req->getHeaderLine('Range'), $len);
        $fp    = fopen($absolutePath, 'rb') ?: null;
        if (!$fp) {
            return new Response(500);
        }

        return self::fromSeekable($fp, $len, $range, $mediaType, $headers);
    }

    /**
     * Generic helper for any **seekable** stream/resource.
     */
    public static function fromSeekable(
        mixed         $source,
        int           $totalLength,
        ?SimpleRange  $range,
        string        $mediaType = 'application/octet-stream',
        array         $headers   = []
    ): Response {
        /* ---------------------------------------------------------
         | No valid Range → full 200
         * -------------------------------------------------------- */
        if ($range === null) {
            $headers += [
                'Content-Type'   => $mediaType,
                'Content-Length' => (string) $totalLength,
            ];
            return new Response(200, self::wrapSeekable($source), $headers);
        }

        /* ---------------------------------------------------------
         | Valid single range → 206
         * -------------------------------------------------------- */
        $length = $range->length();

        // seek to start
        if ($source instanceof StreamInterface) {
            $source->seek($range->start);
        } else {
            fseek($source, $range->start);
        }

        $headers += [
            'Content-Range'  => $range->contentRange(),
            'Content-Length' => (string) $length,
            'Content-Type'   => $mediaType,
        ];

        return new Response(206, self::wrapSeekable($source, $length), $headers);
    }

    /* --------------------------------------------------------------
     |  Internal utils
     |-------------------------------------------------------------- */

    /** Wrap resource/stream into Webrick’s Stream with optional window-limit. */
    private static function wrapSeekable(
        mixed $src,
        ?int                     $limit = null
    ): StreamInterface {
        if ($src instanceof StreamInterface && $limit === null) {
            return $src;
        }

        /* Build temp proxy stream — keeps memory predictable. */
        $tmp = fopen('php://temp', 'r+');
        if ($src instanceof StreamInterface) {
            $src->rewind();
            stream_copy_to_stream($src->detach(), $tmp, $limit ?? -1);
        } else {
            stream_copy_to_stream($src, $tmp, $limit ?? -1);
        }
        rewind($tmp);
        return new Stream($tmp);
    }
}
