<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * Parse a simple byte Range header and format a Content-Range reply.
 *
 * • Only supports single range (`bytes=START-END`).
 * • Caller decides 416 handling; this class just parses.
 */
final class Range
{
    public function __construct(
        public int $start,
        public int $end,
        public int $length, // original resource length
    ) {}

    /** Returns `null` if header invalid / multi-range / unsatisfiable. */
    public static function parse(string $rangeHeader, int $resourceLen): ?self
    {
        if (!preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            return null;
        }
        [$_, $a, $b] = $m + [2 => ''];

        $start = $a === '' ? null : (int)$a;
        $end   = $b === '' ? null : (int)$b;

        if ($start === null && $end === null) {
            return null;
        }
        if ($start === null) {                // suffix bytes
            $start = max(0, $resourceLen - $end);
            $end   = $resourceLen - 1;
        } elseif ($end === null || $end >= $resourceLen) {
            $end = $resourceLen - 1;
        }
        if ($start > $end) {
            return null;
        }
        return new self($start, $end, $resourceLen);
    }

    public function contentRange(): string
    {
        return "bytes {$this->start}-{$this->end}/{$this->length}";
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
