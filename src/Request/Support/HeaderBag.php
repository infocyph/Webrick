<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Support;

/**
 * Small wrapper so we never juggle naked header arrays.
 * Name normalisation (ucwords-dashed) happens **once** in ctor.
 *
 * $bag = new HeaderBag(['content-type' => ['text/html']]);
 * $bag->get('Content-Type');          // 'text/html'
 * $bag->has('ETag');                  // false
 * foreach ($bag as $name => $values){ … }
 */
final class HeaderBag implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /** @var array<string,string[]> */
    private array $hdr;

    public function __construct(array $headers = [])
    {
        foreach ($headers as $k => $v) {
            $norm         = self::norm($k);
            $this->hdr[$norm] = is_array($v) ? array_values($v) : [(string)$v];
        }
    }

    /* ------------- query helpers ------------- */

    public function all(): array
    {
        return $this->hdr;
    }
    public function has(string $n): bool
    {
        return isset($this->hdr[self::norm($n)]);
    }

    /** Returns full array or single string when exactly one value. */
    public function get(string $n): string|array|null
    {
        $v = $this->hdr[self::norm($n)] ?? null;
        return $v && count($v) === 1 ? $v[0] : $v;
    }

    public function line(string $n): ?string
    {
        return ($this->hdr[self::norm($n)][0] ?? null) !== null
            ? implode(',', $this->hdr[self::norm($n)])
            : null;
    }

    /* ------------- IteratorAggregate / Countable ------------- */

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->hdr);
    }
    public function count(): int
    {
        return count($this->hdr);
    }

    /* ------------- ArrayAccess (read-only) ------------- */

    public function offsetExists(mixed $o): bool
    {
        return $this->has((string)$o);
    }
    public function offsetGet(mixed $o): mixed
    {
        return $this->get((string)$o);
    }
    public function offsetSet(mixed $o, mixed $v): void
    {
        throw new \LogicException('Immutable');
    }
    public function offsetUnset(mixed $o): void
    {
        throw new \LogicException('Immutable');
    }

    /* ------------- internals ------------- */

    private static function norm(string $h): string
    {
        return ucwords(strtolower($h), '-');
    }
}
