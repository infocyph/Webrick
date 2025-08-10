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
    public function __construct(
        public int $start,
        public int $end,
        public int $length,
    ) {}

    /**
     * @param non-empty-string $header Raw **Range:** header
     * @param positive-int $resourceLen Full size of the representation
     *
     * @return self|null
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
            $len = (int)$rawEnd;
            if ($len <= 0) {
                return null;
            }

            $start = max(0, $resourceLen - $len);
            return new self($start, $resourceLen - 1, $resourceLen);
        }

        $start = (int)$rawStart;

        /* ---------- open range  bytes=N-  ------------------------------ */
        if ($rawEnd === '' || $rawEnd === null) {
            if ($start >= $resourceLen) {
                return null;
            }
            return new self($start, $resourceLen - 1, $resourceLen);
        }

        /* ---------- explicit range  bytes=N-M  ------------------------- */
        $end = (int)$rawEnd;

        if ($start > $end || $end >= $resourceLen) {
            return null;
        }

        return new self($start, $end, $resourceLen);
    }

    /** RFC-compliant **Content-Range** value */
    public function contentRange(): string
    {
        return "bytes {$this->start}-{$this->end}/{$this->length}";
    }

    /** Length of this partial segment */
    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
