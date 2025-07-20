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
    public static function parse(string $raw, int $resourceLen): ?SimpleRange
    {
        return SimpleRange::parse($raw, $resourceLen);
    }
}
