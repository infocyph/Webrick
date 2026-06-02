<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Headers\Range as SimpleRange;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Response\Response;

/**
 * Builds **206 Partial Content** (or 200 / 416) responses for seek-able sources.
 */
final readonly class RangeResponder
{
    /* ───────────────────────── public factories ───────────────────────── */

    /**
     * Build a Response for a filesystem resource, honouring Range requests.
     *
     * Behaviour:
     *  - Attempts to open $absolutePath for reading; returns 404 if the file cannot be opened.
     *  - Stat's the file to obtain size and mtime and synthesises a quick ETag.
     *  - Adds safe caching and range-related headers (Accept-Ranges, ETag, Last-Modified, Cache-Control)
     *    unless already provided in $headers.
     *  - Parses the client's Range header and delegates the actual response construction
     *    to fromSeekable().
     *
     * @param Request $req Incoming request (used to read Range header / attributes)
     * @param string $absolutePath Filesystem path to the resource to serve
     * @param string $mediaType Media type to use for the response (default application/octet-stream)
     * @param array<string,string> $headers Additional headers to include / preserve
     * @return Response Response instance (200, 206, 404 or 416 as appropriate)
     */
    public static function forFile(
        Request $req,
        string $absolutePath,
        string $mediaType = 'application/octet-stream',
        array $headers = [],
    ): Response {
        // TOCTOU-safe: open first; if it fails, bail out cleanly
        $fp = fopen($absolutePath, 'rb');
        if ($fp === false) {
            return new Response(StatusEnum::NOT_FOUND->value);
        }

        $stat = fstat($fp) ?: [];
        $len = (int) ($stat['size'] ?? 0);
        $mtime = (int) ($stat['mtime'] ?? time());
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

    /**
     * Construct a Response for a seekable source given an optional parsed Range.
     *
     * Behaviour:
     *  - Distinguishes three outcomes:
     *      * 416 — a Range header was provided but was invalid/unsatisfiable (single-range only).
     *      * 200 — no Range requested or a multipart range was requested (unsupported; fallback to full body).
     *      * 206 — single satisfiable byte-range; returns partial content with appropriate headers.
     *  - Ensures representation headers are removed for 416 responses and sets Content-Range: "bytes * /<total>".
     *  - When serving full or partial bodies the function will set Content-Type, Content-Length and
     *    Accept-Ranges where applicable and wrap the source in a ByteRangeStream when only a subrange
     *    should be exposed.
     *
     * @param mixed $source Seekable stream resource or Stream instance (or a filesystem resource handle)
     * @param int $totalLength Total length of the resource in bytes
     * @param SimpleRange|null $range Parsed single byte range or null if none/invalid
     * @param string $mediaType Media type to set on successful responses
     * @param array<string,string> $headers Initial headers to include / preserve
     * @param Request|null $req Optional Request used to detect raw Range header and attributes
     * @return Response Response instance: 200, 206 or 416 as described above
     */
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
            // Ensure we do NOT imply a representation: strip type/encoding/language and force zero-length body
            unset($headers['Content-Type'], $headers['Content-Encoding'], $headers['Content-Language'], $headers['Content-Length']);

            $headers += [
                'Content-Range' => "bytes */{$totalLength}", // RFC 9110 §14.4.2 requirement
                'Content-Length' => '0',
            ];

            return new Response(StatusEnum::RANGE_NOT_SATISFIABLE->value, new Stream(''), $headers);
        }

        // 200 – full body when no Range OR multi-range (unsupported → fallback)
        if ($range === null || $multiRequested) {
            $headers += [
                'Content-Type' => $mediaType,
                'Content-Length' => (string) $totalLength,
            ];
            if (self::isSeekable($source)) {
                $headers += ['Accept-Ranges' => 'bytes'];
            }
            if ($multiRequested) {
                $headers['X-Range-Dropped'] = 'multi';
            } elseif ($req?->getAttribute('range_dropped')) {
                $headers['X-Range-Dropped'] = '1';
            }

            return new Response(StatusEnum::OK->value, self::wrapSeekable($source), $headers);
        }

        // 206 – single byte range (unchanged)
        $length = $range->length();
        if ($source instanceof Stream) {
            $source->seek($range->start);
        } elseif (is_resource($source)) {
            fseek($source, $range->start);
        } else {
            throw new \RuntimeException('Range source must be a Stream or seekable resource.');
        }
        $headers += [
            'Content-Range' => $range->contentRange(),
            'Content-Length' => (string) $length,
            'Content-Type' => $mediaType,
        ];
        if (self::isSeekable($source)) {
            $headers += ['Accept-Ranges' => 'bytes'];
        }

        return new Response(StatusEnum::PARTIAL_CONTENT->value, self::wrapSeekable($source, $length), $headers);
    }

    /**
     * Detect whether the raw Range header requests multiple ranges.
     *
     * The function:
     *  - Trims the input and quickly rejects empty or non-"bytes=" values.
     *  - Returns true if the byte-range-set contains a comma (RFC 7233 multi-range).
     *
     * @param string $raw Raw Range header value
     * @return bool True when a multipart byte-range request was made
     */
    private static function isMultiRange(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '' || !str_starts_with($raw, 'bytes=')) {
            return false;
        }

        // RFC 7233 multi-range = comma-separated byte-range-set
        return str_contains(substr($raw, 6), ',');
    }

    /**
     * Determine if the provided source is seekable.
     *
     * Supports both Stream instances and native PHP stream resources.
     *
     * @param mixed $src Stream instance, resource or other
     * @return bool True when reads can seek on the underlying source
     */
    private static function isSeekable(mixed $src): bool
    {
        return $src instanceof Stream
            ? $src->isSeekable()
            : (is_resource($src) && stream_get_meta_data($src)['seekable']);
    }

    /* ─────────────────────────── internals ───────────────────────────── */

    /**
     * Return either the original seekable Stream or a ByteRangeStream windowing it.
     *
     * - If $src is already a Stream instance it is used directly; otherwise a new
     *   Stream wrapper is created from the native resource.
     * - When $limit is provided a ByteRangeStream is returned that exposes at most
     *   $limit bytes without copying the data; when null the full Stream is returned.
     *
     * @param mixed $src Seekable resource or Stream
     * @param int|null $limit Optional maximum number of bytes the returned stream should expose
     *                        ️ * @return ByteRangeStream|Stream Stream exposing the requested window or the full stream
     */
    private static function wrapSeekable(mixed $src, ?int $limit = null): ByteRangeStream|Stream
    {
        $base = $src instanceof Stream ? $src : new Stream($src);

        return $limit === null
            ? $base
            : new ByteRangeStream($base, $limit);
    }
}
