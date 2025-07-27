<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Headers\Range as SimpleRange;
use Infocyph\Webrick\Response\Internal\Utils;

/**
 * Builds **206 Partial Content** (or 200 / 416) responses for seek-able sources.
 */
final readonly class RangeResponder
{
    /* ───────────────────────── public factories ───────────────────────── */

    public static function forFile(
        Request $req,
        string $absolutePath,
        string $mediaType = 'application/octet-stream',
        array $headers = [],
    ): Response {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return new Response(404);
        }

        $stat = stat($absolutePath);
        $len = (int)$stat['size'];
        $mtime = (int)$stat['mtime'];
        $etag = '"' . dechex($len) . '-' . dechex($mtime) . '"';

        $headers += [
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Last-Modified' => Utils::httpDate($mtime),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];

        $range = RangeParser::parse($req->getHeaderLine('Range'), $len);
        $fp = fopen($absolutePath, 'rb');                    // can’t fail here

        return self::fromSeekable($fp, $len, $range, $mediaType, $headers, $req);
    }

    public static function fromSeekable(
        mixed $source,
        int $totalLength,
        ?SimpleRange $range,
        string $mediaType = 'application/octet-stream',
        array $headers = [],
        ?Request $req = null,
    ): Response {
        /* full-body 200 -------------------------------------------------- */
        if ($range === null) {
            $headers += [
                'Content-Type' => $mediaType,
                'Content-Length' => (string)$totalLength,
            ];
            // Was an invalid Range silently stripped by ConditionalMiddleware?
            if ($req?->getAttribute('range_dropped')) {
                $headers['X-Range-Dropped'] = '1';
            }
            return new Response(200, self::wrapSeekable($source), $headers);
        }

        /* single-range 206 ---------------------------------------------- */
        $length = $range->length();

        if ($source instanceof Stream) {
            $source->seek($range->start);
        } else {
            fseek($source, $range->start);
        }

        $headers += [
            'Content-Range' => $range->contentRange(),
            'Content-Length' => (string)$length,
            'Content-Type' => $mediaType,
        ];

        return new Response(206, self::wrapSeekable($source, $length), $headers);
    }

    /* ─────────────────────────── internals ───────────────────────────── */

    /**
     * Return the given seek-able stream/resource unchanged when the caller
     * wants the whole body; otherwise wrap it in a lightweight view that
     * exposes **at most** `$limit` bytes without copying them first.
     */
    private static function wrapSeekable(mixed $src, ?int $limit = null): ByteRangeStream|Stream
    {
        $base = $src instanceof Stream ? $src : new Stream($src);

        return $limit === null
            ? $base
            : new ByteRangeStream($base, $limit);
    }
}
