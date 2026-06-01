<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Support;

use ArrayAccess;
use ArrayIterator;
use Countable;
use Infocyph\Webrick\Response\Headers\HeaderPolicy;
use IteratorAggregate;
use Traversable;

/**
 * Immutable, case–insensitive header store shared by *Request* and *Response* layers.
 *
 *  • Normalises names once (Uc-Words-Dashed) in the constructor
 *  • Zero reflection, zero magic setters – every mutator clones
 *  • Compatible with PSR-7 semantics ➜ get() **always** returns an array
 *  • Extra helpers: first()   – first header value or null
 *                    value()  – collapse-to-string when exactly one value (legacy)
 *
 * @psalm-type HeaderValues = string|string[]
 */
final class HeaderBag implements ArrayAccess, Countable, IteratorAggregate
{
    private static array $normCache = [];

    /** @var array<string,string[]> */
    private array $map = [];

    /**
     * Constructor.
     *
     * Initializes the HeaderBag with an optional seed array of headers.
     * Each header name is normalized to a standard format.
     *
     * @param array<string,string|string[]> $seed Optional initial headers
     */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $name => $value) {
            $this->set($name, $value);
        }
    }

    /**
     * Retrieve all headers.
     *
     * @return array<string,string[]> An associative array of all headers
     */
    public function all(): array
    {
        return $this->map;
    }

    /**
     * Returns the number of header fields in the collection.
     *
     * @return int The number of header fields.
     */
    public function count(): int
    {
        return count($this->map);
    }

    /**
     * Retrieves the first value of the specified header.
     *
     * If the header is absent or has no values, returns null.
     * If the header has exactly one value, returns that value as a string.
     *
     * @param string $name Case-insensitive header name
     * @return string|null The first header value or null
     */
    public function first(string $name): ?string
    {
        return $this->map[$this->norm($name)][0] ?? null;
    }

    /**
     * Retrieves the values of the specified header.
     *
     * If the header is absent, returns an empty array.
     * Otherwise, returns the values of the header as an array.
     *
     * @param string $name Case-insensitive header name
     * @return array The header values or an empty array if the header is absent
     */
    public function get(string $name): array
    {
        return $this->map[$this->norm($name)] ?? [];
    }

    /**
     * Return a comma-concatenated header line (empty string when header absent).
     *
     * @param string $name Case-insensitive header name
     * @return string Comma-concatenated header line or empty string when header absent
     */
    public function getHeaderLine(string $name): string
    {
        return ($vals = $this->get($name)) ? implode(',', $vals) : '';
    }

    /**
     * Returns an iterator for the collection.
     *
     * The returned iterator will iterate over the header fields in the collection,
     * yielding each header field as a key-value pair where the key is the header
     * name (in lowercase) and the value is an array of strings for each value
     * of the header.
     *
     * @return Traversable The iterator for the collection.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->map);
    }

    /**
     * Check if a header exists.
     *
     * @param string $name Case-insensitive header name
     * @return bool True if header exists, false otherwise
     */
    public function has(string $name): bool
    {
        return isset($this->map[$this->norm($name)]);
    }

    /**
     * Return a comma-concatenated header line (null when header absent).
     *
     * @param string $name Case-insensitive header name
     * @return string|null Comma-concatenated header line or null when header absent
     */
    public function line(string $name): ?string
    {
        return ($vals = $this->get($name)) ? implode(',', $vals) : null;
    }

    /**
     * Check if a given offset exists in the collection.
     *
     * @param mixed $offset The key to check for existence.
     * @return bool True if the offset exists, false otherwise.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    /**
     * Retrieves a header value by name.
     *
     * @param mixed $offset The name of the header to retrieve.
     * @return mixed The header value if present, otherwise null.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    /**
     * This method is not intended to be used directly. HeaderBag is immutable and
     * cannot be modified after creation. Calling this method will throw a
     * \LogicException.
     *
     * @param mixed $offset The header to set.
     * @param mixed $value The value to set.
     *
     * @throws \LogicException Always thrown as HeaderBag is immutable.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('HeaderBag is immutable');
    }

    /**
     * Unsets a header.
     *
     * @param mixed $offset The header to unset.
     *
     * @throws \LogicException HeaderBag is immutable.
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('HeaderBag is immutable');
    }

    /**
     * Retrieves the value of the specified header.
     *
     * If the header is absent, returns `null`.
     * If the header has exactly one value, returns that value as a string.
     * If the header has multiple values, returns an array of those values.
     *
     * @param string $name Case-insensitive header name
     */
    public function value(string $name): string|array|null
    {
        $all = $this->get($name);
        if ($all === []) {
            return null;
        }

        return count($all) === 1 ? $all[0] : $all;
    }

    /**
     * Return a new instance with the specified header set.
     *
     * @param string $name Case-insensitive header name
     * @param string|array $value New header value(s)
     * @return static New instance with the specified header set
     */
    public function with(string $name, string|array $value): self
    {
        $x = clone $this;
        $x->set($name, $value);

        return $x;
    }

    /**
     * Return a new instance with the specified header value(s) added.
     *
     * Header values are merged with existing values:
     * - If the header already exists, the new values are appended to the end.
     * - If the header does not exist, the new values are used as-is.
     *
     * @param string $name Case-insensitive header name
     * @param string|array $value New header values
     * @return static New instance with the specified header value(s) added
     */
    public function withAdded(string $name, string|array $value): self
    {
        $norm = $this->norm($name);
        $x = clone $this;
        $x->map[$norm] = array_merge(
            $x->map[$norm] ?? [],
            is_array($value) ? array_values($value) : [$value],
        );

        return $x;
    }

    /**
     * Return a new instance without the specified header.
     *
     * @param string $name Case-insensitive header name
     * @return static New instance without the specified header
     */
    public function without(string $name): self
    {
        $x = clone $this;
        unset($x->map[$this->norm($name)]);

        return $x;
    }

    /**
     * Modify header according to its configured policy.
     *
     * The policy is as follows:
     * - SINGLE: Replace the header
     * - MULTI_LINE: append the value
     * - MERGE_TOKENS: merge the value with existing header values
     *
     * @param string $name Case-insensitive header name
     * @param string $value New header value
     */
    public function withSmart(string $name, string $value): self
    {
        $policy = HeaderPolicy::for($name);

        return match ($policy) {
            HeaderPolicy::SINGLE => $this->with($name, $value),
            HeaderPolicy::MULTI_LINE => $this->withAdded($name, $value),
            HeaderPolicy::MERGE_TOKENS => $this->with(
                $name,
                HeaderPolicy::mergeCsv($name, $this->getHeaderLine($name), $value),
            ),
        };
    }

    /**
     * Normalize a header name to ucwords-dashed.
     *
     * This method is used internally to normalize header names.
     * It caches the results to avoid repeated computation.
     *
     * @param string $name The header name to normalize.
     * @return string The normalized header name.
     */
    private function norm(string $name): string
    {
        return self::$normCache[$name] ??= ucwords(strtolower($name), '-');
    }

    /**
     * Internal helper to set a header.
     *
     * @param string $name The header name to set.
     * @param string|array $value The header value to set.
     *
     * @throws \LogicException If the header bag is immutable (i.e. if it's being accessed from the Response side).
     */
    private function set(string $name, string|array $value): void
    {
        $this->map[$this->norm($name)] = is_array($value)
            ? array_values($value)
            : [$value];
    }
}
