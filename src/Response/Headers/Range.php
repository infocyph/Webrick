<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Response\Range\RangeParser;
use Infocyph\Webrick\Response\Range\RangeParseStatus;

/** RFC 9110 single-byte-range value object backed by the canonical range parser. */
final readonly class Range
{
    public function __construct(
        public int $start,
        public int $end,
        public int $length,
    ) {
        if ($length <= 0) {
            throw new \InvalidArgumentException('Range resource length must be positive.');
        }
        if ($start < 0 || $end < $start || $end >= $length) {
            throw new \InvalidArgumentException('Range offsets must satisfy 0 <= start <= end < resource length.');
        }
    }

    public static function parse(string $header, int $resourceLen): ?self
    {
        if ($resourceLen <= 0) {
            return null;
        }

        $result = RangeParser::parse($header, $resourceLen);

        return $result->status === RangeParseStatus::SATISFIABLE
            ? $result->requireRange()
            : null;
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
