<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Constraints;

/** Matches integers such as 0, 42, -7 … */
final class IntConstraint implements ConstraintInterface
{
    private int  $minDigits;
    private ?int $maxDigits;

    public function __construct(int $minDigits = 1, ?int $maxDigits = null)
    {
        $this->minDigits = max(1, $minDigits);
        $this->maxDigits = $maxDigits;
    }

    public function pattern(): string
    {
        // {m,n}  or  {m,}
        $range = $this->maxDigits !== null
            ? sprintf('{%d,%d}', $this->minDigits, $this->maxDigits)
            : sprintf('{%d,}', $this->minDigits);

        return '-?[0-9]' . $range;
    }
}
