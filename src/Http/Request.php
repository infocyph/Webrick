<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use InvalidArgumentException;

/**
 * A custom Request that extends your PSR-7 ServerRequest,
 * adding "magic" methods for post, query, file, header, etc.,
 * plus an automatic JSON/XML parser, and a __get() that respects variables_order.
 */
class Request extends ServerRequest
{
    /**
     * Store arrays for parsed GET, POST, FILES, HEADERS, etc.
     */
    protected ?array $parsedPost   = null;
    protected ?array $parsedQuery  = null;
    protected ?array $parsedFiles  = null;
    protected ?array $parsedCookies = null;
    protected ?array $parsedJson   = null;   // if content-type is JSON
    protected ?array $parsedXml    = null;   // if content-type is XML (optional)

    /**
     * Construct the request, optionally parse the body if needed.
     */
    public function __construct(
        string $method,
        UriInterface $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        array $serverParams = []
    ) {
        parent::__construct($method, $uri, $serverParams, [], $headers, $protocolVersion, null, $body);
        // If you want to parse body or do other init, you can do so here or lazily in the methods.
    }

    /**
     * Magic caller:
     *   - post('key') => get POST field
     *   - query('key') => get query param
     *   - file('key') => get file from $this->getUploadedFiles()
     *   - header('Name') => get a single header line
     *   - cookie('key') => get a single cookie
     */
    public function __call(string $method, array $arguments): mixed
    {
        $arg = $arguments[0] ?? null;

        return match ($method) {
            'post'   => $this->getPostValue($arg),
            'query'  => $this->getQueryValue($arg),
            'file'   => $this->getFileValue($arg),
            'header' => $this->getHeaderValue($arg),
            'cookie' => $this->getCookieValue($arg),
            default  => throw new InvalidArgumentException("Unknown method {$method} called on Request"),
        };
    }

    /**
     * Magic getter for $request->key
     * We'll follow something like "variables_order" = "EGPCS" or similar:
     * E = Environment
     * G = GET
     * P = POST
     * C = Cookie
     * S = Server
     * (You can adapt to your needs!)
     */
    public function __get(string $key): mixed
    {
        // Here we just do a naive approach:
        // 1) check environment? (Your call if you want to store environment)
        // 2) check GET
        $fromQuery = $this->getQueryValue($key);
        if ($fromQuery !== null) {
            return $fromQuery;
        }

        // 3) check POST
        $fromPost = $this->getPostValue($key);
        if ($fromPost !== null) {
            return $fromPost;
        }

        // 4) check Cookie
        $cookieVal = $this->getCookieValue($key);
        if ($cookieVal !== null) {
            return $cookieVal;
        }

        // 5) check Server
        $serverParams = $this->getServerParams();
        if (array_key_exists($key, $serverParams)) {
            return $serverParams[$key];
        }

        // If not found, null
        return null;
    }

    /**
     * Lazy parse the POST body if not done, checking for JSON / XML or standard form.
     * Then return $parsedPost[$key] or entire array if $key is null.
     */
    protected function getPostValue(?string $key = null): mixed
    {
        if ($this->parsedPost === null) {
            $this->parsedPost = $this->computePostData();
        }
        if ($key === null) {
            return $this->parsedPost;
        }
        return $this->parsedPost[$key] ?? null;
    }

    /**
     * If content-type is JSON => parse -> stored in $this->parsedJson
     * If content-type is XML => parse -> stored in $this->parsedXml
     * Otherwise, if "application/x-www-form-urlencoded" => parse.
     * Adjust as needed for "multipart/form-data".
     */
    private function computePostData(): array
    {
        $contentType = strtolower($this->getHeaderLine('Content-Type'));
        $rawBody     = (string)$this->getBody();

        // 1) JSON check
        if (str_contains($contentType, 'application/json')) {
            if ($this->parsedJson === null) {
                $decoded = json_decode($rawBody, true);
                $this->parsedJson = is_array($decoded) ? $decoded : [];
            }
            return $this->parsedJson;
        }

        // 2) XML check (optional)
        if (str_contains($contentType, 'application/xml') || str_contains($contentType, 'text/xml')) {
            if ($this->parsedXml === null) {
                // naive parse. For real code, you'd do a robust parse or simplexml_load_string
                $xml = @simplexml_load_string($rawBody);
                $this->parsedXml = $xml ? json_decode(json_encode($xml), true) : [];
            }
            return $this->parsedXml;
        }

        // 3) standard form
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $arr = [];
            parse_str($rawBody, $arr);
            return $arr;
        }

        // else no recognized form => empty
        return [];
    }

    /**
     * Lazy parse the query string from the URI.
     */
    protected function getQueryValue(?string $key = null): mixed
    {
        if ($this->parsedQuery === null) {
            $this->parsedQuery = [];
            parse_str($this->getUri()->getQuery(), $this->parsedQuery);
        }
        if ($key === null) {
            return $this->parsedQuery;
        }
        return $this->parsedQuery[$key] ?? null;
    }

    /**
     * If you want to handle file uploads, typically you do $this->getUploadedFiles().
     * We'll unify them as an array for "file($key)" usage.
     */
    protected function getFileValue(?string $key = null): mixed
    {
        if ($this->parsedFiles === null) {
            // getUploadedFiles() returns an array of UploadedFileInterface or arrays
            // e.g. ["field1" => UploadedFileInterface, ...]
            // We might transform them into a nested array or keep them as is.
            // Let's keep them as is.
            $this->parsedFiles = $this->getUploadedFiles();
        }
        if ($key === null) {
            return $this->parsedFiles;
        }
        return $this->parsedFiles[$key] ?? null;
    }

    /**
     * Grab single header or array from $this->getHeaders().
     * But in your magic method, we want just one line => getHeaderLine($key).
     */
    protected function getHeaderValue(?string $headerName = null): mixed
    {
        if ($headerName === null) {
            // if no key provided, return the entire headers array?
            return $this->getHeaders();
        }
        return $this->getHeaderLine($headerName);
    }

    /**
     * Cookies. In a full "ServerRequest", you typically have getCookieParams().
     * We'll do a naive approach reading from $this->getHeaderLine('Cookie').
     */
    protected function getCookieValue(?string $key = null): mixed
    {
        if ($this->parsedCookies === null) {
            $this->parsedCookies = $this->computeCookies();
        }
        if ($key === null) {
            return $this->parsedCookies;
        }
        return $this->parsedCookies[$key] ?? null;
    }

    /**
     * Parse "Cookie: name=value; name2=value2" if you want a naive approach.
     */
    private function computeCookies(): array
    {
        $cookieLine = $this->getHeaderLine('Cookie');
        if ($cookieLine === '') {
            return [];
        }
        // naive parse
        $cookies = [];
        $pairs = explode(';', $cookieLine);
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            $split = explode('=', $pair, 2);
            if (count($split) === 2) {
                $cookies[trim($split[0])] = trim($split[1]);
            }
        }
        return $cookies;
    }
}
