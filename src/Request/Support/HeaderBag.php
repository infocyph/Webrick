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
 * Immutable, case–insensitive header store shared by Request and Response.
 *
 * @implements ArrayAccess<string, list<string>>
 * @implements IteratorAggregate<string, list<string>>
 */
final class HeaderBag implements ArrayAccess, Countable, IteratorAggregate
{
    private const string INVALID_HEADER_NAME = "/[^!#$%&'*+.^_`|~0-9A-Za-z-]/";

    private const string INVALID_HEADER_VALUE = '/[\x00-\x08\x0A-\x1F\x7F]/';

    private const int NORMALIZATION_CACHE_LIMIT = 256;

    /** @var array<string,string> */
    private static array $normCache = [];

    /** @var array<string,list<string>> */
    private array $map = [];

    /** @param array<array-key,mixed> $seed */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_array($value))) {
                throw new \InvalidArgumentException('HTTP headers must use string names and string or array values.');
            }
            $this->set($name, $value);
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

    /**
     * @return list<string>
     * @param string $name
     */
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

    public function offsetGet(mixed $offset): mixed
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

    /**
     * @return string|list<string>|null
     * @param string $name
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
     * @param string|array<int,string> $value
     * @param string $name
     */
    public function with(string $name, string|array $value): self
    {
        $copy = clone $this;
        $copy->set($name, $value);

        return $copy;
    }

    /**
     * @param string|array<array-key,mixed> $value
     * @param string $name
     */
    public function withAdded(string $name, string|array $value): self
    {
        $normalized = $this->norm($name);
        $values = $this->normalizeValues($value);
        $copy = clone $this;
        $copy->map[$normalized] = array_merge($copy->map[$normalized] ?? [], $values);

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
        $policy = HeaderPolicy::for($name);

        return match ($policy) {
            HeaderPolicy::SINGLE => $this->with($name, $value),
            HeaderPolicy::MULTI_LINE => $this->withAdded($name, $value),
            HeaderPolicy::MERGE_TOKENS => $this->with(
                $name,
                HeaderPolicy::mergeCsv($name, $this->getHeaderLine($name), $value),
            ),
            default => $this->with($name, $value),
        };
    }

    /**
     * Cache hits are values already validated at the trust boundary, so they may
     * bypass the header-name regex on subsequent requests in persistent workers.
     * @param string $name
     */
    private function norm(string $name): string
    {
        if (isset(self::$normCache[$name])) {
            return self::$normCache[$name];
        }
        if ($name === '' || preg_match(self::INVALID_HEADER_NAME, $name) === 1) {
            throw new \InvalidArgumentException('Invalid HTTP header name.');
        }

        $normalized = ucwords(strtolower($name), '-');
        if (count(self::$normCache) < self::NORMALIZATION_CACHE_LIMIT) {
            self::$normCache[$name] = $normalized;
        }

        return $normalized;
    }

    /** @param string|array<array-key,mixed> $value @return list<string> */
    private function normalizeValues(string|array $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        foreach ($values as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('HTTP header values must be strings.');
            }
            if (preg_match(self::INVALID_HEADER_VALUE, $item) === 1) {
                throw new \InvalidArgumentException('HTTP header values must not contain control characters.');
            }
        }

        return $values;
    }

    /**
     * @param string|array<array-key,mixed> $value
     * @param string $name
     */
    private function set(string $name, string|array $value): void
    {
        $this->map[$this->norm($name)] = $this->normalizeValues($value);
    }
}
