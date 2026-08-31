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
 * Immutable, case-insensitive header store shared by Request and Response.
 *
 * @implements ArrayAccess<string,list<string>>
 * @implements IteratorAggregate<string,list<string>>
 */
final class HeaderBag implements ArrayAccess, Countable, IteratorAggregate
{
    private const string INVALID_HEADER_NAME = "/[^!#$%&'*+.^_`|~0-9A-Za-z-]/";

    private const string INVALID_HEADER_VALUE = '/[\x00-\x08\x0A-\x1F\x7F]/';

    /** @var array<string,list<string>> */
    private array $map = [];

    /** @param array<array-key,mixed> $seed */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $name => $value) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException('HTTP headers must use string names.');
            }
            $this->set($name, $this->normalizeSeedValue($value));
        }
    }

    /** @return array<string,list<string>> */
    public function all(): array
    {
        return $this->map;
    }

    public function count(): int
    {
        return count($this->map);
    }

    public function first(string $name): ?string
    {
        return $this->map[$this->norm($name)][0] ?? null;
    }

    /** @return list<string> */
    public function get(string $name): array
    {
        return $this->map[$this->norm($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return ($values = $this->get($name)) ? implode(',', $values) : '';
    }

    /** @return Traversable<string,list<string>> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->map);
    }

    public function has(string $name): bool
    {
        return isset($this->map[$this->norm($name)]);
    }

    public function line(string $name): ?string
    {
        return ($values = $this->get($name)) ? implode(',', $values) : null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->has($offset);
    }

    /** @return list<string> */
    public function offsetGet(mixed $offset): array
    {
        return is_string($offset) ? $this->get($offset) : [];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('HeaderBag is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('HeaderBag is immutable');
    }

    /** @return string|list<string>|null */
    public function value(string $name): string|array|null
    {
        $all = $this->get($name);
        if ($all === []) {
            return null;
        }

        return count($all) === 1 ? $all[0] : $all;
    }

    /** @param string|list<string> $value */
    public function with(string $name, string|array $value): self
    {
        $copy = clone $this;
        $copy->set($name, $value);

        return $copy;
    }

    /** @param string|list<string> $value */
    public function withAdded(string $name, string|array $value): self
    {
        $values = $this->normalizeValues($value);
        if ($values === []) {
            return $this;
        }

        $normalized = $this->norm($name);
        $copy = clone $this;
        $current = $copy->map[$normalized] ?? [];
        foreach ($values as $item) {
            $current[] = $item;
        }
        $copy->map[$normalized] = $current;

        return $copy;
    }

    public function without(string $name): self
    {
        $copy = clone $this;
        unset($copy->map[$this->norm($name)]);

        return $copy;
    }

    public function withSmart(string $name, string $value): self
    {
        return match (HeaderPolicy::for($name)) {
            HeaderPolicy::SINGLE => $this->with($name, $value),
            HeaderPolicy::MULTI_LINE => $this->withAdded($name, $value),
            HeaderPolicy::MERGE_TOKENS => $this->with($name, HeaderPolicy::mergeCsv($name, $this->getHeaderLine($name), $value)),
            default => $this->with($name, $value),
        };
    }

    private function norm(string $name): string
    {
        if ($name === '' || preg_match(self::INVALID_HEADER_NAME, $name) === 1) {
            throw new \InvalidArgumentException('Invalid HTTP header name.');
        }

        return ucwords(strtolower($name), '-');
    }

    /** @return string|list<string> */
    private function normalizeSeedValue(mixed $value): string|array
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('HTTP header values must be strings or lists of strings.');
        }

        $values = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('HTTP header values must be strings or lists of strings.');
            }
            $values[] = $item;
        }

        return $values;
    }

    /**
     * @param string|list<string> $value
     * @return list<string>
     */
    private function normalizeValues(string|array $value): array
    {
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('HTTP header values must be strings or lists of strings.');
            }
            if (preg_match(self::INVALID_HEADER_VALUE, $item) === 1) {
                throw new \InvalidArgumentException('HTTP header values must be valid strings without control characters.');
            }
        }

        return $values;
    }

    /** @param string|list<string> $value */
    private function set(string $name, string|array $value): void
    {
        $this->map[$this->norm($name)] = $this->normalizeValues($value);
    }
}
