<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Response\Headers\Range;

/**
 * RFC-style parser for one byte range with explicit outcome semantics.
 */
final class RangeParser
{
    public static function parse(string $raw, int $resourceLen): RangeParseResult
    {
        $raw = trim($raw);
        if ($raw === '') {
            return RangeParseResult::none();
        }
        if ($resourceLen < 0) {
            throw new \InvalidArgumentException('Resource length cannot be negative.');
        }
        if (!preg_match('/^bytes=(.+)$/i', $raw, $unitMatch)) {
            return RangeParseResult::malformed();
        }

        return self::parseSpec(trim($unitMatch[1]), $resourceLen);
    }

    private static function parseSpec(string $spec, int $resourceLen): RangeParseResult
    {
        if (str_contains($spec, ',')) {
            return RangeParseResult::multiple();
        }
        if (!preg_match('/^(\d*)-(\d*)$/', $spec, $match)) {
            return RangeParseResult::malformed();
        }

        $rawStart = $match[1];
        $rawEnd = $match[2];
        if ($rawStart === '' && $rawEnd === '') {
            return RangeParseResult::malformed();
        }
        if ($resourceLen === 0) {
            return RangeParseResult::unsatisfiable();
        }

        if ($rawStart === '') {
            $suffixLength = (int) $rawEnd;
            if ($suffixLength <= 0) {
                return RangeParseResult::unsatisfiable();
            }

            $start = max(0, $resourceLen - $suffixLength);

            return RangeParseResult::satisfiable(new Range($start, $resourceLen - 1, $resourceLen));
        }

        $start = (int) $rawStart;
        if ($start >= $resourceLen) {
            return RangeParseResult::unsatisfiable();
        }

        if ($rawEnd === '') {
            return RangeParseResult::satisfiable(new Range($start, $resourceLen - 1, $resourceLen));
        }

        $end = (int) $rawEnd;
        if ($end < $start) {
            return RangeParseResult::malformed();
        }

        return RangeParseResult::satisfiable(new Range($start, min($end, $resourceLen - 1), $resourceLen));
    }
}
