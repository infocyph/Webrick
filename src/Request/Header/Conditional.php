<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Header;

use Infocyph\ArrayKit\Collection\Collection;

/**
 * Parses If-* / Range / Prefer headers as per RFC 9110 §13.
 *
 * Call `$conditional->all()` for the whole bag or individual getters.
 */
final readonly class Conditional
{
    /** @var Collection{if_match:list<string>,if_none_match:list<string>,if_modified_since:?int,if_unmodified_since:?int,prefer_safe:bool,range:?array{unit:string,span:list<string>}} */
    private Collection $all;

    public function __construct(
        string $ifMatch,
        string $ifNoneMatch,
        string $ifModifiedSince,
        string $ifUnmodifiedSince,
        string $range,
        string $preferHeader
    ) {
        $this->all = Collection::from([
            'if_match'          => self::csv($ifMatch),
            'if_none_match'     => self::csv($ifNoneMatch),
            'if_modified_since' => self::httpDate($ifModifiedSince),
            'if_unmodified_since' => self::httpDate($ifUnmodifiedSince),
            'prefer_safe'       => \strcasecmp($preferHeader, 'safe') === 0,
            'range'             => self::parseRange($range),
        ]);
    }

    public function all(): Collection
    {
        return $this->all;
    }

    /* -- convenience accessors ----------------------------------- */
    public function __get(string $key): mixed
    {
        return $this->all[$key] ?? null;
    }

    /* -- tiny helpers -------------------------------------------- */
    private static function csv(string $v): array
    {
        return $v === '' ? [] : \preg_split('/\s*,\s*/', $v);
    }

    private static function httpDate(string $v): ?int
    {
        return $v === '' ? null : (\strtotime($v) ?: null);
    }

    /** @return array{unit:string,span:list<string>}|null */
    private static function parseRange(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        [$unit, $span] = \array_pad(
            \explode('=', \str_replace(' ', '', $raw), 2),
            2,
            ''
        );

        return $unit !== ''
            ? ['unit' => $unit, 'span' => \explode(',', $span)]
            : null;
    }
}
