<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

class ServerRequest extends Request implements ServerRequestInterface
{
    private array $cookieParams = [];

    private array $queryParams = [];

    private array $uploadedFiles = [];

    private $parsedBody;

    private array $attributes = [];

    public function __construct(
        string $method,
        UriInterface $uri,
        private array $serverParams = [],
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
    ) {
        parent::__construct($method, $uri, $headers, $body, $protocolVersion);
    }

    // --- ServerRequestInterface methods ---
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $new = clone $this;
        $new->cookieParams = $cookies;

        return $new;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $new = clone $this;
        $new->queryParams = $query;

        return $new;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        // Validate structure
        array_walk_recursive($uploadedFiles, function ($file) {
            if (!$file instanceof UploadedFileInterface) {
                throw new InvalidArgumentException('Invalid uploaded file in structure.');
            }
        });

        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;

        return $new;
    }

    public function getParsedBody(): object|array|null
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $new = clone $this;
        $new->parsedBody = $data;

        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($name, $default = null)
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute($name, $value): ServerRequestInterface
    {
        $new = clone $this;
        $new->attributes[$name] = $value;

        return $new;
    }

    public function withoutAttribute($name): ServerRequestInterface
    {
        $new = clone $this;
        unset($new->attributes[$name]);

        return $new;
    }
}
