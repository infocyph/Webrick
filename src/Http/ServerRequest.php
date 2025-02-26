<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Infocyph\ArrayKit\Collection\Collection;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * A PSR-7 ServerRequest *plus* "CommonAsset"-like helpers.
 *
 * It implements all mandatory PSR-7 methods:
 *   - getServerParams(), getCookieParams(), withCookieParams()
 *   - getQueryParams(), withQueryParams()
 *   - getUploadedFiles(), withUploadedFiles()
 *   - getParsedBody(), withParsedBody()
 *   - getAttributes(), getAttribute(), withAttribute(), withoutAttribute()
 * ... and so on.
 *
 * Additionally, it adds convenience methods like:
 *   - query($key)
 *   - post($key)
 *   - parsedJson($key)
 *   - cookie($key)
 *   - raw() // entire body as string
 *   - file($key)
 */
class ServerRequest implements ServerRequestInterface
{
    // ---------------------------------
    // Standard PSR-7 properties
    // ---------------------------------
    protected string $method;
    protected UriInterface $uri;
    protected array $cookieParams = [];
    protected array $queryParams  = [];
    protected array $attributes = [];
    protected array $headers = [];

    // ---------------------------------
    // Additional helpers
    // ---------------------------------
    private ?Collection $queryCollection  = null;
    private ?Collection $postCollection   = null;
    private ?Collection $cookieCollection = null;
    private ?Collection $serverCollection = null;
    private ?Collection $jsonCollection   = null; // for parsed JSON
    private ?Collection $filesCollection  = null;

    private ?string $rawBodyCache = null;

    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param array $serverParams
     * @param array $headers
     * @param StreamInterface $body
     * @param string $protocolVersion
     * @param mixed|null $parsedBody
     * @param array $uploadedFiles
     * @param string|null $requestTarget
     */
    public function __construct(
        string $method,
        UriInterface|string $uri,
        protected array $serverParams = [],
        array $headers = [],
        protected StreamInterface $body = new Stream(''),
        protected string $protocolVersion = '1.1',
        protected mixed $parsedBody = null,
        protected array $uploadedFiles = [],
        protected ?string $requestTarget = null
    ) {
        $this->method          = strtoupper($method);
        $this->uri             = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->headers         = $this->normalizeHeaders($headers);

        if ($this->hasHeader('Host') === false && $this->uri->getHost() !== '') {
            $host = $this->uri->getHost();
            if ($this->uri->getPort()) {
                $host .= ':' . $this->uri->getPort();
            }
            $this->headers['Host'] = [$host];
        }
    }

    // ---------------------------------------------------
    // PSR-7: MessageInterface
    // ---------------------------------------------------
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): self
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

    public function withHeader($name, $value): self
    {
        $new = clone $this;
        $normalized = $this->normalizeHeaderName($name);
        $new->headers[$normalized] = is_array($value) ? array_values($value) : [(string)$value];
        return $new;
    }

    public function withAddedHeader($name, $value): self
    {
        $new = clone $this;
        $normalized = $this->normalizeHeaderName($name);
        if (!isset($new->headers[$normalized])) {
            $new->headers[$normalized] = [];
        }
        if (is_array($value)) {
            foreach ($value as $val) {
                $new->headers[$normalized][] = $val;
            }
        } else {
            $new->headers[$normalized][] = $value;
        }
        return $new;
    }

    public function withoutHeader($name): self
    {
        $new = clone $this;
        $normalized = $this->normalizeHeaderName($name);
        unset($new->headers[$normalized]);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): self
    {
        $new = clone $this;
        $new->body = $body;
        // Invalidate raw body cache
        $new->rawBodyCache = null;
        return $new;
    }

    // ---------------------------------------------------
    // PSR-7: RequestInterface
    // ---------------------------------------------------
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
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

    public function withRequestTarget($requestTarget): self
    {
        if (preg_match('#\s#', $requestTarget)) {
            throw new InvalidArgumentException('Invalid request target (contains whitespace).');
        }
        $new = clone $this;
        $new->requestTarget = $requestTarget;
        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($method): self
    {
        $new = clone $this;
        $new->method = strtoupper($method);
        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): self
    {
        $new = clone $this;
        $new->uri = $uri;
        if (!$preserveHost) {
            if ($uri->getHost() !== '') {
                $host = $uri->getHost();
                if ($uri->getPort()) {
                    $host .= ':' . $uri->getPort();
                }
                $new->headers['Host'] = [$host];
            } else {
                unset($new->headers['Host']);
            }
        } elseif ($uri->getHost() !== '' && !$new->hasHeader('Host')) {
            $host = $uri->getHost();
            if ($uri->getPort()) {
                $host .= ':' . $uri->getPort();
            }
            $new->headers['Host'] = [$host];
        }
        return $new;
    }

    // ---------------------------------------------------
    // PSR-7: ServerRequestInterface
    // ---------------------------------------------------
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): self
    {
        $new = clone $this;
        $new->cookieParams = $cookies;
        // Invalidate the collection
        $new->cookieCollection = null;
        return $new;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): self
    {
        $new = clone $this;
        $new->queryParams = $query;
        $new->queryCollection = null;
        return $new;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @param UploadedFileInterface[] $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): self
    {
        // Validate structure if needed
        array_walk_recursive($uploadedFiles, function ($item) {
            if (!$item instanceof UploadedFileInterface) {
                throw new InvalidArgumentException('Invalid uploaded file');
            }
        });

        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;
        $new->filesCollection = null;
        return $new;
    }

    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): self
    {
        $new = clone $this;
        $new->parsedBody = $data;
        // Invalidate post and json collection
        $new->postCollection = null;
        $new->jsonCollection = null;
        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute($name, $value): self
    {
        $new = clone $this;
        $new->attributes[$name] = $value;
        return $new;
    }

    public function withoutAttribute($name): self
    {
        $new = clone $this;
        unset($new->attributes[$name]);
        return $new;
    }

    // ---------------------------------------------------
    // Internals
    // ---------------------------------------------------
    private function normalizeHeaderName(string $name): string
    {
        return ucwords(strtolower($name), '-');
    }

    private function normalizeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $val) {
            $normName = $this->normalizeHeaderName($name);
            $result[$normName] = is_array($val) ? array_values($val) : [(string)$val];
        }
        return $result;
    }

    // ---------------------------------------------------
    // Additional "CommonAsset"-like convenience methods
    // ---------------------------------------------------

    /**
     * "Raw" entire request body as string
     */
    public function raw(): string
    {
        if ($this->rawBodyCache === null) {
            $this->rawBodyCache = (string) $this->body;
        }
        return $this->rawBodyCache;
    }

    /**
     * A Collection wrapper over the server params
     */
    public function server(?string $key = null): mixed
    {
        if ($this->serverCollection === null) {
            $this->serverCollection = new Collection($this->serverParams);
        }
        return $this->fetch($this->serverCollection, $key);
    }

    /**
     * A Collection wrapper over cookie params
     */
    public function cookie(?string $key = null): mixed
    {
        if ($this->cookieCollection === null) {
            $this->cookieCollection = new Collection($this->cookieParams);
        }
        return $this->fetch($this->cookieCollection, $key);
    }

    /**
     * A Collection wrapper over query params
     */
    public function query(?string $key = null): mixed
    {
        if ($this->queryCollection === null) {
            $this->queryCollection = new Collection($this->queryParams);
        }
        return $this->fetch($this->queryCollection, $key);
    }

    /**
     * A Collection wrapper for "POST" form data,
     * but only if getParsedBody() is an array
     */
    public function post(?string $key = null): mixed
    {
        if ($this->postCollection === null) {
            $parsed = $this->parsedBody;
            $arr = is_array($parsed) ? $parsed : [];
            $this->postCollection = new Collection($arr);
        }
        return $this->fetch($this->postCollection, $key);
    }

    /**
     * Attempt to decode JSON from the raw body if Content-Type is JSON.
     * If invalid JSON, throw exception.
     * If not JSON, fallback to post().
     */
    public function parsedJson(?string $key = null): mixed
    {
        if ($this->jsonCollection === null) {
            // Check content type
            $contentType = $this->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $decoded = json_decode($this->raw(), true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Invalid JSON data in request body');
                }
                $this->jsonCollection = new Collection(is_array($decoded) ? $decoded : []);
            } else {
                // not JSON => store an empty collection to skip repeatedly checking
                $this->jsonCollection = new Collection([]);
            }
        }
        // If we actually decoded something, fetch from it
        if ($this->jsonCollection->isEmpty()) {
            // fallback to post
            return $this->post($key);
        }
        return $this->fetch($this->jsonCollection, $key);
    }

    /**
     * Access a single file or entire array of files as a Collection
     * (Though typically you'd just do getUploadedFiles() for PSR-7 style)
     */
    public function file(?string $key = null): mixed
    {
        if ($this->filesCollection === null) {
            $this->filesCollection = new Collection($this->uploadedFiles);
        }
        return $this->fetch($this->filesCollection, $key);
    }

    /**
     * Helper to fetch either a single key or entire array from a Collection
     */
    private function fetch(Collection $collection, ?string $key): mixed
    {
        if ($key === null) {
            return $collection;
        }
        return $collection->$key;
    }
}
