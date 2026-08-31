<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use Infocyph\Webrick\Interfaces\BodyStream;

/** Lean immutable HTTP-message base used by the native request model. */
abstract class Message
{
    protected BodyStream $body;

    /** @var array<string,list<string>> */
    protected array $headers;

    /** @param array<string,string|array<int,string>> $headers */
    protected function __construct(
        array $headers = [],
        ?BodyStream $body = null,
        protected string $protocol = '1.1',
    ) {
        $this->headers = $this->normalise($headers);
        $this->body = $body ?? new StringBody('');
    }

    protected function __clone(): void {}

    public function getBody(): BodyStream
    {
        return $this->body;
    }

    /** @return list<string> */
    public function getHeader(string $name): array
    {
        return $this->headers[$this->norm($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(',', $this->getHeader($name));
    }

    /** @return array<string,list<string>> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$this->norm($name)]);
    }

    /** @param string|array<int,string> $value */
    public function withAddedHeader(string $name, string|array $value): static
    {
        $norm = $this->norm($name);
        $values = $this->normalizeHeaderValues($value);
        if (!$this->hasHeader($norm)) {
            return $this->withHeader($norm, $values);
        }
        if ($values === [] || array_diff($values, $this->headers[$norm]) === []) {
            return $this;
        }
        $clone = clone $this;
        $clone->headers[$norm] = array_merge($this->headers[$norm], $values);

        return $clone;
    }

    public function withBody(BodyStream $body): static
    {
        return $body === $this->body ? $this : $this->withPropertyValue('body', $body);
    }

    /** @param string|array<int,string> $value */
    public function withHeader(string $name, string|array $value): static
    {
        $norm = $this->norm($name);
        $values = $this->normalizeHeaderValues($value);
        if (($this->headers[$norm] ?? null) === $values) {
            return $this;
        }

        return $this->withMappedHeaderValue($norm, $values);
    }

    public function withoutHeader(string $name): static
    {
        if (!$this->hasHeader($name)) {
            return $this;
        }
        $clone = clone $this;
        unset($clone->headers[$this->norm($name)]);

        return $clone;
    }

    public function withProtocolVersion(string $version): static
    {
        return $version === $this->protocol ? $this : $this->withPropertyValue('protocol', $version);
    }

    private function norm(string $name): string
    {
        return ucwords(strtolower($name), '-');
    }

    /** @param array<string,string|array<int,string>> $headers @return array<string,list<string>> */
    private function normalise(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[$this->norm($name)] = $this->normalizeHeaderValues($value);
        }

        return $normalized;
    }

    /** @return list<string> */
    private function normalizeHeaderValues(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return is_scalar($value) ? [(string) $value] : [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $normalized[] = $item;
            } elseif (is_scalar($item)) {
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
