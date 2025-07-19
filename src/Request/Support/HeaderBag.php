<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Request\Support;

use Countable;
use IteratorAggregate;
use ArrayAccess;
use Traversable;
use ArrayIterator;

/**
 * Immutable, case–insensitive header store shared by *Request* and *Response* layers.
 *
 *  • Normalises names once (Uc-Words-Dashed) in the constructor
 *  • Zero reflection, zero magic setters – every mutator clones
 *  • Compatible with PSR-7 semantics ➜ get() **always** returns an array
 *  • Extra helpers: first()   – first header value or null
 *                    value()  – collapse-to-string when exactly one value (legacy)
 *
 *  @psalm-type HeaderValues = string|string[]
 */
final class HeaderBag implements IteratorAggregate, Countable, ArrayAccess
{
    /** @var array<string,string[]> */
    private array $map = [];
    private static array $normCache = [];

    /**
     * @param array<string,HeaderValues> $seed header-name ➜ string OR string[]
     */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $name => $value) {
            $this->set($name, $value);
        }
    }

    /* -----------------------------------------------------------------
     *  Public read helpers
     * ---------------------------------------------------------------- */

    /** @return array<string,string[]> */
    public function all(): array
    {
        return $this->map;
    }

    public function has(string $name): bool
    {
        return isset($this->map[$this->norm($name)]);
    }

    /** PSR-7 semantics – **always returns string[]** (empty when absent). */
    public function get(string $name): array
    {
        return $this->map[$this->norm($name)] ?? [];
    }

    /** First element or `null` when header missing. */
    public function first(string $name): ?string
    {
        return $this->map[$this->norm($name)][0] ?? null;
    }

    /**
     * Legacy helper – collapse to scalar when single-valued,
     * otherwise return the full array (keeps BC with pre-merge code).
     */
    public function value(string $name): string|array|null
    {
        $all = $this->get($name);
        if ($all === []) {
            return null;
        }
        return count($all) === 1 ? $all[0] : $all;
    }

    /** Comma-concatenated header line (`null` when header absent). */
    public function line(string $name): ?string
    {
        return ($vals = $this->get($name)) ? implode(',', $vals) : null;
    }

    /* -----------------------------------------------------------------
     *  Immutable write helpers – used by Response side
     * ---------------------------------------------------------------- */

    /** Replace header (cloned). */
    public function with(string $name, string|array $value): self
    {
        $x = clone $this;
        $x->set($name, $value);
        return $x;
    }

    /** Append header value(s) (cloned). */
    public function withAdded(string $name, string|array $value): self
    {
        $norm        = $this->norm($name);
        $x           = clone $this;
        $x->map[$norm] = array_merge(
            $x->map[$norm] ?? [],
            is_array($value) ? array_values($value) : [(string)$value],
        );
        return $x;
    }

    /** Remove header completely (cloned). */
    public function without(string $name): self
    {
        $x = clone $this;
        unset($x->map[$this->norm($name)]);
        return $x;
    }

    /* -----------------------------------------------------------------
     *  IteratorAggregate / Countable
     * ---------------------------------------------------------------- */

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->map);
    }

    public function count(): int
    {
        return count($this->map);
    }

    /* -----------------------------------------------------------------
     *  ArrayAccess (read-only)
     * ---------------------------------------------------------------- */

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string)$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string)$offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('HeaderBag is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('HeaderBag is immutable');
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------------- */

    /**
     * @param HeaderValues $value
     */
    private function set(string $name, string|array $value): void
    {
        $this->map[$this->norm($name)] = is_array($value)
            ? array_values($value)
            : [$value];
    }

    private function norm(string $name): string
    {
        return self::$normCache[$name] ??= ucwords(strtolower($name), '-');
    }
}
