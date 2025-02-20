<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class Response implements ResponseInterface
{
    protected int $statusCode;

    protected string $reasonPhrase;

    protected array $headers;

    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        protected StreamInterface $body = new Stream(''),
        string $reasonPhrase = '',
        protected string $protocolVersion = '1.1',
    ) {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('Invalid status code.');
        }
        $this->statusCode = $statusCode;
        $this->headers = $this->normalizeHeaders($headers);
        $this->reasonPhrase = $reasonPhrase ?: $this->getDefaultReasonPhrase($statusCode);
    }

    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $val) {
            $n = ucwords(strtolower($name), '-');
            $out[$n] = is_array($val) ? array_values($val) : [(string)$val];
        }

        return $out;
    }

    private function getDefaultReasonPhrase(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            default => ''
        };
    }

    // --- MessageInterface ---
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
        $name = ucwords(strtolower($name), '-');

        return isset($this->headers[$name]);
    }

    public function getHeader($name): array
    {
        $name = ucwords(strtolower($name), '-');

        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(',', $this->getHeader($name));
    }

    public function withHeader($name, $value): MessageInterface
    {
        $new = clone $this;
        $n = ucwords(strtolower($name), '-');
        $new->headers[$n] = is_array($value) ? array_values($value) : [(string)$value];

        return $new;
    }

    public function withAddedHeader($name, $value): MessageInterface
    {
        $new = clone $this;
        $n = ucwords(strtolower($name), '-');
        if (!isset($new->headers[$n])) {
            $new->headers[$n] = [];
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                $new->headers[$n][] = $v;
            }
        } else {
            $new->headers[$n][] = (string)$value;
        }

        return $new;
    }

    public function withoutHeader($name): MessageInterface
    {
        $new = clone $this;
        $n = ucwords(strtolower($name), '-');
        unset($new->headers[$n]);

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

    // --- ResponseInterface ---
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function withStatus($code, $reasonPhrase = ''): ResponseInterface
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException('Invalid status code.');
        }
        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase ?: $this->getDefaultReasonPhrase($code);

        return $new;
    }
}
