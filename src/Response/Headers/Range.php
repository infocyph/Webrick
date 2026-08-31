<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * RFC 7233 single byte-range helper.
 *
 *  • `parse()` returns `null` when header is invalid / not satisfiable.
 *  • On success you get a value-object exposing
 *        →  `$start`, `$end`, `$length` (original size)
 *        →  `contentRange()`   – header string for replies
 *        →  `length()`         – length of the partial chunk
 */
final readonly class Range
{
    /**
     * Create a Range value object.
     *
     * @param int $start Inclusive start offset (0-based).
     * @param int $end Inclusive end offset.
     * @param int $length Total length of the resource (must be positive).
     *
     * Callers should ensure 0 <= $start <= $end < $length. The constructor
     * does not perform heavy validation to stay allocation-light on the hot-path.
     */
    public function __construct(
        public int $start,
        public int $end,
        public int $length,
    ) {}

    /**
     * Parse a Range header and return a Range object for the first satisfiable byte-range.
     *
     * Supported forms (RFC 7233):
     *  - "bytes=N-M" explicit range (inclusive)
     *  - "bytes=N-"   open range to end
     *  - "bytes=-N"   suffix range (last N bytes)
     *
     * Only the first range is considered; multipart ranges are not supported.
     * Returns null when the header is malformed or the requested range is not satisfiable.
     *
     * @param non-empty-string $header Raw Range header value (e.g. "bytes=0-499")
     * @param positive-int $resourceLen Total size of the resource in bytes
     * @return self|null Parsed Range instance or null if invalid / unsatisfiable
     */
    public static function parse(string $header, int $resourceLen): ?self
    {
        $header = trim($header);

        /* ---------- fast-fail ------------------------------------------ */
        if ($header === '' || !str_starts_with($header, 'bytes=')) {
            return null;
        }

        // We’re interested only in the *first* range (no multipart support).
        [$rawStart, $rawEnd] = explode('-', substr($header, 6), 2) + [null, null];

        /* ---------- suffix range  bytes=-N  ---------------------------- */
        if ($rawStart === '') {
            $len = (int) $rawEnd;
            if ($len <= 0) {
                return null;
            }

            $start = max(0, $resourceLen - $len);

            return new self($start, $resourceLen - 1, $resourceLen);
        }

        $start = (int) $rawStart;

        /* ---------- open range  bytes=N-  ------------------------------ */
        if ($rawEnd === '' || $rawEnd === null) {
            if ($start >= $resourceLen) {
                return null;
            }

            return new self($start, $resourceLen - 1, $resourceLen);
        }

        /* ---------- explicit range  bytes=N-M  ------------------------- */
        $end = (int) $rawEnd;

        if ($start > $end || $end >= $resourceLen) {
            return null;
        }

        return new self($start, $end, $resourceLen);
    }

    /**
     * Produce an RFC 7233 compliant Content-Range header value for this range.
     *
     * Example: "bytes 0-499/1234"
     *
     * @return string Content-Range header value
     */
    public function contentRange(): string
    {
        return "bytes {$this->start}-{$this->end}/{$this->length}";
    }

    /**
     * Length (in bytes) of the partial segment represented by this Range.
     *
     * Computed as (end - start + 1).
     *
     * @return int Number of bytes in the range
     */
    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
