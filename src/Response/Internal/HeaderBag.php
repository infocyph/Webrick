<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Internal;

/** Fast, case-insensitive header store (immutable). */
final class HeaderBag
{
    /** @var array<string,string[]> */
    private array $map = [];

    public function __construct(array $seed = [])
    {
        foreach ($seed as $n => $v) {
            $this->set($n, $v);
        }
    }

    public function all(): array
    {
        return $this->map;
    }
    public function has(string $n): bool
    {
        return isset($this->map[$this->norm($n)]);
    }
    public function get(string $n): array
    {
        return $this->map[$this->norm($n)] ?? [];
    }
    public function line(string $n): string
    {
        return implode(',', $this->get($n));
    }

    public function with(string $n, string|array $v): self
    {
        $x = clone $this;
        $x->set($n, $v);
        return $x;
    }

    public function withAdded(string $n, string|array $v): self
    {
        $norm = $this->norm($n);
        $x = clone $this;
        $x->map[$norm] = array_merge(
            $x->map[$norm] ?? [],
            is_array($v) ? $v : [$v]
        );
        return $x;
    }

    public function without(string $n): self
    {
        $x = clone $this;
        unset($x->map[$this->norm($n)]);
        return $x;
    }

    /* -------------------- helpers -------------------------------- */
    private function set(string $n, string|array $v): void
    {
        $this->map[$this->norm($n)] = is_array($v) ? array_values($v) : [(string) $v];
    }
    private function norm(string $n): string
    {
        return ucwords(strtolower($n), '-');
    }
}
