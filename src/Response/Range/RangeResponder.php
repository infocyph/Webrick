<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Infocyph\Webrick\Response\Headers\Range as SimpleRange;
use Psr\Http\Message\StreamInterface;

/**
 * Build a **206 Partial Content** (or 200 / 416) response for seekable sources.
 *
 * *Optimised for static files but works with any seekable StreamInterface.*
 *
 * ```php
 * $resp = RangeResponder::forFile($req, '/tmp/video.mp4', 'video/mp4');
 * ```
 */
final class RangeResponder
{
    /* --------------------------------------------------------------
     |  Public factory helpers
     |-------------------------------------------------------------- */

    public static function forFile(
        \Psr\Http\Message\ServerRequestInterface $req,
        string                                   $absolutePath,
        string                                   $mediaType = 'application/octet-stream',
        array                                    $headers   = []
    ): Response {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return new Response(404);                        // or throw
        }

        $len   = (int) filesize($absolutePath);
        $range = RangeParser::parse($req->getHeaderLine('Range'), $len);

        $fp = fopen($absolutePath, 'rb');
        if ($fp === false) {
            return new Response(500);
        }
        return self::fromSeekable(
            $fp,
            $len,
            $range,
            $mediaType,
            $headers + ['Accept-Ranges' => 'bytes']
        );
    }

    /**
     * Generic helper for any **seekable** stream/resource.
     *
     * `$source` may be:
     *  • a resource (from `fopen()` or similar) **or**
     *  • a PSR-7 StreamInterface that is `isSeekable() === true`.
     *
     * The caller is responsible for closing the resource.
     */
    public static function fromSeekable(
        resource|StreamInterface  $source,
        int                       $totalLength,
        ?SimpleRange              $range,
        string                    $mediaType   = 'application/octet-stream',
        array                     $headers     = []
    ): Response {

        /* -----------------------------------------------------------------
         | No Range header OR unsatisfiable → fall back to full 200
         * ---------------------------------------------------------------- */
        if ($range === null) {
            if ($headers) {                           // ensure clean keys
                $headers = array_change_key_case($headers, CASE_TITLE);
            }
            $headers += [
                'Content-Type'   => $mediaType,
                'Content-Length' => (string) $totalLength,
            ];
            $body   = self::wrapSeekable($source);
            return new Response(200, $body, $headers);
        }

        /* -----------------------------------------------------------------
         | Valid single range → 206 Partial Content
         * ---------------------------------------------------------------- */
        $length = $range->length();

        // Move pointer
        if ($source instanceof StreamInterface) {
            $source->seek($range->start);
        } else {
            fseek($source, $range->start);
        }

        $body = self::wrapSeekable($source, $length);

        $headers += [
            'Content-Range'  => $range->contentRange(),
            'Content-Length' => (string) $length,
            'Content-Type'   => $mediaType,
        ];

        return new Response(206, $body, $headers);
    }

    /* --------------------------------------------------------------
     |  Internal utils
     |-------------------------------------------------------------- */

    /**
     * Wrap a seekable resource/stream into Webrick’s Stream,
     * optionally limiting the readable window.
     */
    private static function wrapSeekable(
        resource|StreamInterface $src,
        ?int                     $limit = null
    ): StreamInterface {
        // If the underlying Stream is already ours & has correct bounds use it.
        if ($src instanceof StreamInterface && $limit === null) {
            return $src;
        }

        // Build temp stream that proxies reads up to `$limit` bytes.
        $pipe = fopen('php://temp', 'r+');
        if ($src instanceof StreamInterface) {
            $src->rewind();
            stream_copy_to_stream($src->detach(), $pipe, $limit ?? -1);
        } else {
            stream_copy_to_stream($src, $pipe, $limit ?? -1);
        }
        rewind($pipe);
        return new Stream($pipe);
    }
}
