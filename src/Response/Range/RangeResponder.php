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
        // TOCTOU-safe: open first; if it fails, bail out cleanly
        $fp = @fopen($absolutePath, 'rb');
        if ($fp === false) {
            return new Response(404);
        }

        $stat = fstat($fp) ?: [];
        $len = (int)($stat['size'] ?? 0);
        $mtime = (int)($stat['mtime'] ?? time());
        $etag = '"' . dechex($len) . '-' . dechex($mtime) . '"';

        $headers += [
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Last-Modified' => Utils::httpDate($mtime),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];

        $range = RangeParser::parse($req->getHeaderLine('Range'), $len);

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
        // Distinguish “no Range header” from “unsatisfiable/invalid Range header”
        $rawRangeHeader = $req?->getHeaderLine('Range') ?? '';
        $rangeHeaderGiven = $rawRangeHeader !== '';
        $multiRequested = self::isMultiRange($rawRangeHeader);

        // 416 – unsatisfiable / invalid Range (but NOT multi-range)
        if ($range === null && $rangeHeaderGiven && !$multiRequested) {
            $headers += ['Content-Range' => "bytes */{$totalLength}"];
            return new Response(416, new Stream(''), $headers);
        }

        // 200 – full body when no Range OR multi-range (unsupported → fallback)
        if ($range === null || $multiRequested) {
            $headers += [
                'Content-Type' => $mediaType,
                'Content-Length' => (string)$totalLength,
            ];
            if (self::isSeekable($source)) {
                $headers += ['Accept-Ranges' => 'bytes'];
            }
            if ($multiRequested) {
                $headers['X-Range-Dropped'] = 'multi';
            } elseif ($req?->getAttribute('range_dropped')) {
                $headers['X-Range-Dropped'] = '1';
            }
            return new Response(200, self::wrapSeekable($source), $headers);
        }

        // 206 – single byte range (unchanged)
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
        if (self::isSeekable($source)) {
            $headers += ['Accept-Ranges' => 'bytes'];
        }
        return new Response(206, self::wrapSeekable($source, $length), $headers);
    }

    private static function isMultiRange(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '' || !str_starts_with($raw, 'bytes=')) {
            return false;
        }
        // RFC 7233 multi-range = comma-separated byte-range-set
        return str_contains(substr($raw, 6), ',');
    }

    private static function isSeekable(mixed $src): bool
    {
        return $src instanceof Stream
            ? $src->isSeekable()
            : (is_resource($src) && (stream_get_meta_data($src)['seekable'] ?? false));
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
