<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

class Request implements RequestInterface
{
    protected string $method;

    protected array $headers = [];

    protected string $requestTarget = '';

    public function __construct(
        string $method,
        protected UriInterface $uri,
        array $headers = [],
        protected ?StreamInterface $body = null,
        protected string $protocolVersion = '1.1',
    ) {
        $this->method = strtoupper($method);
        $this->body ??= new Stream('');
        $this->headers = $this->normalizeHeaders($headers);
        $this->updateHostHeader();
    }

    private function updateHostHeader(): void
    {
        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $host = $this->uri->getHost();
            if ($this->uri->getPort()) {
                $host .= ':' . $this->uri->getPort();
            }
            $this->headers['Host'] = [$host];
        }
    }

    private function normalizeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $val) {
            $norm = $this->normalizeHeaderName($name);
            $result[$norm] = is_array($val) ? array_values($val) : [(string)$val];
        }

        return $result;
    }

    private function normalizeHeaderName($name): string
    {
        return ucwords(strtolower((string)$name), '-');
    }

    // --- PSR-7: MessageInterface ---
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): MessageInterface
    {
        $new = clone $this;
        $new->protocolVersion = $version;

        return $new;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader($name): bool
    {
        $name = $this->normalizeHeaderName($name);

        return isset($this->headers[$name]);
    }

    public function getHeader($name): array
    {
        $name = $this->normalizeHeaderName($name);

        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(',', $this->getHeader($name));
    }

    public function withHeader($name, $value): MessageInterface
    {
        $new = clone $this;
        $norm = $this->normalizeHeaderName($name);
        $new->headers[$norm] = is_array($value) ? array_values($value) : [(string)$value];

        return $new;
    }

    public function withAddedHeader($name, $value): MessageInterface
    {
        $new = clone $this;
        $norm = $this->normalizeHeaderName($name);
        if (!isset($new->headers[$norm])) {
            $new->headers[$norm] = [];
        }
        if (is_array($value)) {
            foreach ($value as $val) {
                $new->headers[$norm][] = $val;
            }
        } else {
            $new->headers[$norm][] = $value;
        }

        return $new;
    }

    public function withoutHeader($name): MessageInterface
    {
        $new = clone $this;
        $norm = $this->normalizeHeaderName($name);
        unset($new->headers[$norm]);

        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $new = clone $this;
        $new->body = $body;

        return $new;
    }

    // --- PSR-7: RequestInterface ---
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== '') {
            return $this->requestTarget;
        }
        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }
        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    public function withRequestTarget($requestTarget): RequestInterface
    {
        if (preg_match('#\s#', $requestTarget)) {
            throw new InvalidArgumentException('Invalid request target (whitespace).');
        }
        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($method): RequestInterface
    {
        $new = clone $this;
        $new->method = strtoupper($method);

        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): RequestInterface
    {
        $new = clone $this;
        $new->uri = $uri;
        if (!$preserveHost) {
            $new->updateHostHeader();
        } elseif ($uri->getHost() && !$new->hasHeader('Host')) {
            $new->updateHostHeader();
        }

        return $new;
    }
}
