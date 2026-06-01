<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Response\Headers\Range as SimpleRange;

/**
 * Thin façade around the lightweight `Headers\Range` parser.
 *
 * Gives you either a parsed **single** range or `null`
 * (invalid / multi-range not supported).
 *
 * ```php
 * $r = RangeParser::parse($req->getHeaderLine('Range'), $length);
 * if ($r) {
 *     // $r->start, $r->end, $r->length()
 * }
 * ```
 */
final class RangeParser
{
    /**
     * Parse a raw HTTP "Range" header and return a single range object.
     *
     * Delegates to Infocyph\Webrick\Response\Headers\Range::parse() and returns
     * the parsed Range value object for the first satisfiable byte-range.
     * Returns null when the header is malformed, requests multipart ranges,
     * or the requested range is unsatisfiable for the given resource length.
     *
     * @param string $raw Raw "Range" header value (e.g. "bytes=0-499")
     * @param int $resourceLen Total size of the resource in bytes (positive integer)
     * @return SimpleRange|null Parsed Range instance or null if invalid / unsatisfiable
     */
    public static function parse(string $raw, int $resourceLen): ?SimpleRange
    {
        if ($resourceLen < 1) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        return SimpleRange::parse($raw, $resourceLen);
    }
}
