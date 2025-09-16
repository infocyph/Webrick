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
    protected string $protocol = '1.1';
    protected array $headers = [];
    protected Stream $body;
    /**
     * @param array<string,string[]> $headers header-name => string[] or string (ucwords-dashed)
     * @param Stream|null $body body stream or null for an empty stream
     * @param string $proto HTTP protocol version (e.g. "1.1")
     */
    protected function __construct(array $headers = [], ?Stream $body = null, string $proto = '1.1')
    {
        $this->headers = $this->normalise($headers);
        $this->body = $body ?? new Stream();
        $this->protocol = $proto;
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
     * Create a new instance with the specified HTTP protocol version.
     *
     * @param string $version HTTP protocol version
     * @return static
     */
    public function withProtocolVersion($version): static
    {
        if ($version === $this->protocol) {
            return $this;
        }
        $c = clone $this;
        $c->protocol = $version;
        return $c;
    }


    /**
     * Retrieves the headers of this request.
     *
     * Headers are returned as an associative array where the key is the header
     * name (in lowercase) and the value is an array of strings for each value
     * of the header.
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Check if a header exists.
     *
     * @param string $name Case-insensitive header name
     * @return bool True if header exists, false otherwise
     */
    public function hasHeader($name): bool
    {
        return isset($this->headers[$this->norm($name)]);
    }

    /**
     * Retrieve a header by name.
     *
     * @param string $name Case-insensitive header name
     * @return array Header values or empty array if header not present
     */
    public function getHeader($name): array
    {
        return $this->headers[$this->norm($name)] ?? [];
    }

    /**
     * Comma-concatenated header line (`null` when header absent).
     *
     * @param string $name Case-insensitive header name
     * @return string|null
     */
    public function getHeaderLine($name): string
    {
        return implode(',', $this->getHeader($name));
    }

    /**
     * Return a new instance with the specified header, replacing any existing values.
     * If the header already exists with the same values, the original instance is returned.
     *
     * @param string $name Case-insensitive header name
     * @param string|array $value New header values
     * @return static
     */
    public function withHeader($name, $value): static
    {
        $norm = $this->norm($name);
        $val = is_array($value) ? array_values($value) : [(string)$value];
        if (($this->headers[$norm] ?? null) === $val) {
            return $this;
        }
        $c = clone $this;
        $c->headers[$norm] = $val;
        return $c;
    }

    /**
     * Append header value(s) (cloned).
     *
     * @param string $name
     * @param string|array $value
     * @return static
     */
    public function withAddedHeader($name, $value): static
    {
        $norm = $this->norm($name);
        $val = is_array($value) ? $value : [(string)$value];
        if (!$this->hasHeader($norm)) {
            return $this->withHeader($norm, $val);
        }
        if ($val === array_intersect($val, $this->headers[$norm])) {
            return $this;                       // already present
        }
        $c = clone $this;
        $c->headers[$norm] = array_merge($this->headers[$norm], $val);
        return $c;
    }

    /**
     * Create a new instance without the specified header.
     *
     * @param string $name Header name to remove
     * @return static New instance without the specified header
     */
    public function withoutHeader($name): static
    {
        if (!$this->hasHeader($name)) {
            return $this;
        }
        $c = clone $this;
        unset($c->headers[$this->norm($name)]);
        return $c;
    }


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
     * Create a new instance with the specified body.
     *
     * @param Stream $body The new body stream
     * @return static
     */
    public function withBody(Stream $body): static
    {
        if ($body === $this->body) {
            return $this;
        }
        $c = clone $this;
        $c->body = $body;
        return $c;
    }

    /**
     * Normalise header names to ucwords-dashed.
     *
     * @param string $n Header name
     * @return string Normalised header name
     */
    private function norm(string $n): string
    {
        static $cache = [];
        return $cache[$n] ??= ucwords(strtolower($n), '-');
    }


    /**
     * Normalise the header names to ucwords-dashed and flatten the array to a single level.
     *
     * @param array $h Header array
     * @return array Normalised header array
     */
    private function normalise(array $h): array
    {
        $out = [];
        foreach ($h as $k => $v) {
            $out[$this->norm($k)] = is_array($v) ? array_values($v) : [(string)$v];
        }
        return $out;
    }

    /**
     * Cloning is disabled.
     */
    protected function __clone(): void
    {
    }
}
