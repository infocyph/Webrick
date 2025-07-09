<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Support;

/**
 * RFC 7233 byte-range parser.
 *
 * Turns a header such as “`bytes=0-499`”, “`bytes=-500`”, “`bytes=9500-`”
 * into a `[start, end]` tuple, or `null` when invalid / unsatisfied.
 */
final class Range
{
    private const UNIT = 'bytes';

    /**
     * @return array{0:int,1:int}|null  Null ⇒ ignore header / 416 flow.
     */
    public static function parse(string $header, int $resourceSize): ?array
    {
        // Fast-fail → only “bytes=”
        if (!str_starts_with(trim($header), self::UNIT . '=')) {
            return null;
        }

        // Strip unit, explode first range spec only (we don’t do multipart).
        [$start, $end] = explode('-', substr($header, 6)) + [null, null];

        // Empty range “bytes=-N”  → last N bytes
        if ($start === '') {
            $length = (int)$end;
            if ($length <= 0) {
                return null;
            }
            return [$resourceSize - $length, $resourceSize - 1];
        }

        $start = (int)$start;

        // “bytes=N-”  → from N to EOF
        if ($end === '' || $end === null) {
            if ($start >= $resourceSize) {
                return null;
            }
            return [$start, $resourceSize - 1];
        }

        $end = (int)$end;

        // Normal “bytes=N-M”
        if ($start > $end || $end >= $resourceSize) {
            return null;
        }

        return [$start, $end];
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
