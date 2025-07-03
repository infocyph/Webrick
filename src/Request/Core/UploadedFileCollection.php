<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Thin immutable wrapper so we never expose naked arrays.
 * Implements Countable, ArrayAccess, IteratorAggregate.
 */
final class UploadedFileCollection implements ArrayAccess, Countable, IteratorAggregate
{
    /** @var array<string, UploadedFile|UploadedFile[]> */
    private array $bag;

    public function __construct(array $files = [])
    {
        $this->bag = $files; // assume already normalised
    }

    /* ---- access helpers ---- */
    public function all(): array
    {
        return $this->bag;
    }
    public function has(string $key): bool
    {
        return isset($this->bag[$key]);
    }
    public function get(string $k): mixed
    {
        return $this->bag[$k] ?? null;
    }

    /* ---- Countable / IteratorAggregate ---- */
    public function count(): int
    {
        return count($this->bag);
    }
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->bag);
    }

    /* ---- ArrayAccess (read-only) ---- */
    public function offsetExists(mixed $o): bool
    {
        return isset($this->bag[$o]);
    }
    public function offsetGet(mixed $o): mixed
    {
        return $this->bag[$o] ?? null;
    }
    public function offsetSet(mixed $o, mixed $v): void
    {
        throw new \LogicException('Immutable');
    }
    public function offsetUnset(mixed $o): void
    {
        throw new \LogicException('Immutable');
    }
}
