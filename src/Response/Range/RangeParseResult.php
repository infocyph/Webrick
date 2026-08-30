<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Response\Headers\Range;
use LogicException;

final readonly class RangeParseResult
{
    private function __construct(
        public RangeParseStatus $status,
        public ?Range $range = null,
    ) {}

    public static function malformed(): self
    {
        return new self(RangeParseStatus::MALFORMED);
    }

    public static function multiple(): self
    {
        return new self(RangeParseStatus::MULTIPLE);
    }

    public static function none(): self
    {
        return new self(RangeParseStatus::NONE);
    }

    public static function satisfiable(Range $range): self
    {
        return new self(RangeParseStatus::SATISFIABLE, $range);
    }

    public static function unsatisfiable(): self
    {
        return new self(RangeParseStatus::UNSATISFIABLE);
    }

    public function requireRange(): Range
    {
        if (!$this->range instanceof Range) {
            throw new LogicException('Range parse result does not contain a satisfiable range.');
        }

        return $this->range;
    }
}
