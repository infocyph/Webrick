<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Header;

use Infocyph\ArrayKit\Collection\Collection;


final readonly class Accept
{
    /** @var Collection<array{value:string,q:float,wild:int}> */
    private Collection $ordered;

    public function __construct(string $raw)
    {
        $items = [];
        foreach (\array_map('trim', \explode(',', $raw)) as $segment) {
            if ($segment === '') {
                continue;
            }

            [$token, $param] = \array_pad(\explode(';', $segment, 2), 2, '');
            $q     = \preg_match('/q=([\d.]+)/i', $param, $m) ? (float) $m[1] : 1.0;
            $wild  = \substr_count($token, '*');

            $items[] = ['value' => $token, 'q' => $q, 'wild' => $wild];
        }

        \usort(
            $items,
            static fn ($a, $b) => [$b['q'], $a['wild']] <=> [$a['q'], $b['wild']]
        );

        $this->ordered = Collection::from($items);
    }

    /** Return mime-types in quality order (highest … lowest). */
    public function values(): Collection
    {
        return Collection::from(
            \array_column($this->ordered->toArray(), 'value')
        );
    }

    public function first(): ?string
    {
        return $this->values()->first();
    }

    /** key = mime-type, value = q */
    public function qualityMap(): Collection
    {
        $map = [];
        foreach ($this->ordered as $row) {
            $map[$row['value']] = $row['q'];
        }

        return Collection::from($map);
    }
}
