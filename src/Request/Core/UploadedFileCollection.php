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

    /**
     * Constructs a new UploadedFileCollection.
     *
     * @param array<string,UploadedFile|UploadedFile[]> $files An associative array of uploaded files.
     *   Key is the name of the field, value is either an UploadedFile or an array of UploadedFile objects.
     */
    public function __construct(array $files = [])
    {
        $this->bag = $files; // assume already normalised
    }

    /**
     * Returns all uploaded files in the collection.
     *
     * @return array<string, UploadedFile|UploadedFile[]> An associative array of all uploaded files.
     */
    public function all(): array
    {
        return $this->bag;
    }

    /**
     * Returns the number of uploaded files in the collection.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->bag);
    }

    /**
     * Retrieve an uploaded file from the collection by key.
     *
     * Returns null if the key does not exist in the collection.
     *
     * @param string $k Key (name) of the uploaded file to retrieve
     * @return UploadedFile|null UploadedFile instance if found, null otherwise
     */
    public function get(string $k): mixed
    {
        return $this->bag[$k] ?? null;
    }

    /**
     * Returns an iterator for the collection.
     *
     * The returned iterator will iterate over the uploaded files in the collection,
     * yielding each uploaded file as a key-value pair where the key is the name of
     * the uploaded file and the value is the UploadedFile instance.
     *
     * @return Traversable The iterator for the collection.
     */
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->bag);
    }

    /**
     * Checks if an uploaded file exists in the collection by key.
     *
     * @param string $key Key of the uploaded file.
     * @return bool True if the uploaded file exists, false otherwise.
     */
    public function has(string $key): bool
    {
        return isset($this->bag[$key]);
    }

    /**
     * Check if a given offset exists in the collection.
     *
     * @param mixed $offset The key to check for existence.
     * @return bool True if the offset exists, false otherwise.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->bag[$offset]);
    }

    /**
     * Returns the value associated with the given key or null if the key is not present.
     *
     * @param mixed $offset The key to retrieve.
     * @return mixed The associated value or null if not found.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->bag[$offset] ?? null;
    }

    /**
     * Attempts to set a key in the collection will result in a LogicException as the collection is immutable.
     *
     * @param mixed $offset The key to set.
     * @param mixed $value The value to set.
     *
     * @throws \LogicException Always thrown as the collection is immutable.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Immutable');
    }

    /**
     * Attempting to unset a key will result in a LogicException as the collection is immutable.
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Immutable');
    }
}
