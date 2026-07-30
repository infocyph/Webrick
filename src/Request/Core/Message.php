<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

/**
 * Lean PSR-7 message base-class:
 *  • Immutable  (`with*()` clones)
 *  • Normalises header names once (ucwords-dashed)
 *  • Re-uses Core\Stream for the body
 *
 *  NOTE: kept internal-final – only ServerRequest / Response extend it.
 */
abstract class Message
{
    private const int NORMALIZATION_CACHE_LIMIT = 256;

    protected Stream $body;

    /** @var array<string,list<string>> */
    protected array $headers;

    /**
     * @param array<string,string|array<int,string>> $headers header-name => string[] or string (ucwords-dashed)
     * @param Stream|null $body body stream or null for an empty stream
     * @param string $protocol HTTP protocol version (e.g. "1.1")
     */
    protected function __construct(array $headers = [], ?Stream $body = null, protected string $protocol = '1.1')
    {
        $this->headers = $this->normalise($headers);
        $this->body = $body ?? new Stream();
    }

    /**
     * Cloning is disabled.
     */
    protected function __clone(): void {}

    /**
     * Returns the current message body as an instance of Stream.
     *
     * @return Stream The current message body.
     */
    public function getBody(): Stream
    {
        return $this->body;
    }

    /**
     * Retrieve a header by name.
     *
     * @param string $name Case-insensitive header name
     * @return list<string> Header values or empty array if header not present
     */
    public function getHeader(string $name): array
    {
        return $this->headers[$this->norm($name)] ?? [];
    }

    /**
     * Comma-concatenated header line (`null` when header absent).
     *
     * @param string $name Case-insensitive header name
     */
    public function getHeaderLine(string $name): string
    {
        return implode(',', $this->getHeader($name));
    }

    /**
     * Retrieves the headers of this request.
     *
     * Headers are returned as an associative array where the key is the header
     * name (in lowercase) and the value is an array of strings for each value
     * of the header.
     *
     * @return array<string,list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Retrieve the HTTP protocol version as a string (e.g. "1.1")
     *
     * @return string The HTTP protocol version
     */
    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    /**
     * Check if a header exists.
     *
     * @param string $name Case-insensitive header name
     * @return bool True if header exists, false otherwise
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$this->norm($name)]);
    }

    /**
     * Append header value(s) (cloned).
     *
     * @param string|array<int,string> $value
     */
    public function withAddedHeader(string $name, string|array $value): static
    {
        $norm = $this->norm($name);
        $val = $this->normalizeHeaderValues($value);
        if (!$this->hasHeader($norm)) {
            return $this->withHeader($norm, $val);
        }
        if ($val === [] || array_diff($val, $this->headers[$norm]) === []) {
            return $this;                       // already present
        }
        $c = clone $this;
        $c->headers[$norm] = array_merge($this->headers[$norm], $val);

        return $c;
    }

    /**
     * Create a new instance with the specified body.
     *
     * @param Stream $body The new body stream
     */
    public function withBody(Stream $body): static
    {
        if ($body === $this->body) {
            return $this;
        }

        return $this->withPropertyValue('body', $body);
    }

    /**
     * Return a new instance with the specified header, replacing any existing values.
     * If the header already exists with the same values, the original instance is returned.
     *
     * @param string $name Case-insensitive header name
     * @param string|array<int,string> $value New header values
     */
    public function withHeader(string $name, string|array $value): static
    {
        $norm = $this->norm($name);
        $val = $this->normalizeHeaderValues($value);
        if (($this->headers[$norm] ?? null) === $val) {
            return $this;
        }

        return $this->withMappedHeaderValue($norm, $val);
    }

    /**
     * Create a new instance without the specified header.
     *
     * @param string $name Header name to remove
     * @return static New instance without the specified header
     */
    public function withoutHeader(string $name): static
    {
        if (!$this->hasHeader($name)) {
            return $this;
        }
        $c = clone $this;
        unset($c->headers[$this->norm($name)]);

        return $c;
    }

    /**
     * Create a new instance with the specified HTTP protocol version.
     *
     * @param string $version HTTP protocol version
     */
    public function withProtocolVersion(string $version): static
    {
        if ($version === $this->protocol) {
            return $this;
        }

        return $this->withPropertyValue('protocol', $version);
    }

    /**
     * Normalise header names to ucwords-dashed.
     *
     * @param string $n Header name
     * @return string Normalised header name
     */
    private function norm(string $n): string
    {
        /** @var array<string,string> $cache */
        static $cache = [];

        if (isset($cache[$n])) {
            return $cache[$n];
        }

        $normalized = ucwords(strtolower($n), '-');
        if (count($cache) < self::NORMALIZATION_CACHE_LIMIT) {
            $cache[$n] = $normalized;
        }

        return $normalized;
    }

    /**
     * Normalise the header names to ucwords-dashed and flatten the array to a single level.
     *
     * @param array<string,string|array<int,string>> $h Header array
     * @return array<string,list<string>> Normalised header array
     */
    private function normalise(array $h): array
    {
        $out = [];
        foreach ($h as $k => $v) {
            $out[$this->norm($k)] = $this->normalizeHeaderValues($v);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function normalizeHeaderValues(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }
        if (!\is_array($value)) {
            return \is_scalar($value) ? [(string) $value] : [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (\is_string($item)) {
                $normalized[] = $item;
            } elseif (\is_scalar($item)) {
                $normalized[] = (string) $item;
            }
        }

        return $normalized;
    }

    /** @param list<string> $value */
    private function withMappedHeaderValue(string $name, array $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    private function withPropertyValue(string $property, mixed $value): static
    {
        $clone = clone $this;
        $clone->{$property} = $value;

        return $clone;
    }
}
