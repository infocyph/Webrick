<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7;

use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Request\Core\Message;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Core\UploadedFileCollection;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Http\RequestHeaders;
use Infocyph\Webrick\Support\HttpUtils;
use InvalidArgumentException;

/** @phpstan-consistent-constructor */
class ServerRequest extends Message
{
    /* Valid verbs */
    private const array VALID = [
        HttpMethodEnum::GET->value,
        HttpMethodEnum::POST->value,
        HttpMethodEnum::PUT->value,
        HttpMethodEnum::DELETE->value,
        HttpMethodEnum::PATCH->value,
        HttpMethodEnum::HEAD->value,
        HttpMethodEnum::OPTIONS->value,
        HttpMethodEnum::CONNECT->value,
        HttpMethodEnum::TRACE->value,
    ];

    private static bool $methodParamOverride = false;

    /** @var array<string,mixed> */
    private array $attributes = [];

    private bool $checkEnv = false;

    /** @var array<string, mixed> */
    private array $cookie;

    /* runtime caches */
    private ?Collection $cookieCol = null;

    private ?string $effectiveMethod = null;

    private ?UploadedFileCollection $filesColl = null;

    /** @var array<string, UploadedFile|array<mixed>>|null */
    private ?array $filesHydrated = null;

    /** @var array<string, mixed> */
    private array $filesSpec;

    private ?RequestHeaders $hdrFacade = null;

    private ?Collection $jsonCol = null;

    private string $method;

    private ?Collection $postCol = null;

    /** @var array<string, mixed> */
    private array $query;

    private ?Collection $queryCol = null;

    private ?string $rawBody = null;

    private ?Collection $serverCol = null;

    private Uri $uri;

    /* variable-order map */
    /** @var array<string, mixed>|null */
    private ?array $varMap = null;

    private ?Collection $xmlCol = null;

    /**
     * Create a new ServerRequest instance.
     *
     * The constructor takes various parameters to build a PSR-7 ServerRequest
     * object. It is recommended to use the static factory methods to create a
     * request object from globals.
     *
     * @param string $method The HTTP method (e.g. GET, POST, etc.)
     * @param Uri|string $uri The URI object or a string that can be parsed into a URI object.
     * @param array<string, mixed> $server Server parameters (e.g. $_SERVER)
     * @param array<string, string|list<string>> $headers Headers (e.g. $_SERVER['HTTP_*'])
     * @param Stream $body The request body as a Stream object.
     * @param string $httpVer The HTTP protocol version (e.g. "1.1")
     * @param array<string, mixed>|object|null $parsed The parsed request body (e.g. JSON, XML, etc.)
     * @param array<string, mixed> $files The $_FILES superglobal array.
     * @param string|null $requestTarget The request target (e.g. "index.php").
     */
    public function __construct(
        string $method,
        Uri|string $uri,
        private array $server = [],
        array $headers = [],
        Stream $body = new Stream(),
        string $httpVer = '1.1',
        private array|object|null $parsed = null,
        array $files = [],
        private ?string $requestTarget = null,
    ) {
        parent::__construct(ServerRequestHeaderNormalizer::normalize($headers), $body, $httpVer);

        $this->method = HttpMethodEnum::normalize($method);
        $this->uri = $uri instanceof Uri ? $uri : new Uri($uri);
        $this->filesSpec = $files !== [] ? $files : self::mixedMap($_FILES);

        /* copies of super-globals */
        $this->cookie = self::mixedMap($_COOKIE);
        $this->query = self::mixedMap($_GET);

        /* Host header fallback */
        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $this->headers['Host'] = [
                $this->uri->getHost()
                . ($this->uri->getPort() ? ':' . $this->uri->getPort() : ''),
            ];
        }

    }

    /**
     * Magic property isset() for the varMap.
     *
     * @param string $key key to check
     * @return mixed|null value of the key in the varMap, or null if the key does not exist
     */
    public function __get(string $key): mixed
    {
        $this->buildVariableMap();
        $map = $this->varMap ?? [];
        if (\array_key_exists($key, $map)) {
            return $map[$key];
        }
        if ($this->checkEnv && ($e = getenv($key)) !== false) {
            return $this->varMap[$key] = $e;
        }

        return null;
    }

    /**
     * Magic property isset() for the varMap.
     *
     * @param string $key key to check
     * @return bool true if the key exists in the varMap, false otherwise
     */
    public function __isset(string $key): bool
    {
        return $this->__get($key) !== null;
    }

    /**
     * Disallow dynamic property writes to preserve request immutability.
     *
     * @param string $name Property name
     * @param mixed $value Attempted value
     *
     * @throws InvalidArgumentException Always; request objects are immutable.
     */
    public function __set(string $name, mixed $value): void
    {
        throw new InvalidArgumentException('Request is immutable');
    }

    /**
     * Create a new ServerRequest object from $_SERVER superglobal.
     *
     * @return static A new ServerRequest object.
     */
    public static function createFromGlobals(): static
    {
        $srv = self::serverMap($_SERVER);
        $uri = Uri::fromServerParams($srv);
        $body = self::openInputStream();
        $httpVer = self::detectHttpVersion($srv);
        $headers = RequestHeaders::extractFromServer($srv);

        // build request (headers re-imported once below)
        $req = new static(
            HttpMethodEnum::normalize(self::serverString($srv, 'REQUEST_METHOD', HttpMethodEnum::GET->value)),
            $uri,
            $srv,
            $headers,
            $body,
            $httpVer,
            self::mixedMap($_POST),
            UploadedFilesNormalizer::normalise(self::mixedMap($_FILES)),
        );

        $req = self::importHeadersOnce($req);
        $req = self::maybeParseUrlEncodedForNonPost($req, $body);

        return self::attachQueryAndCookies($req, $uri);
    }

    /**
     * Returns whether the request object should interpret the '_method' parameter as overriding the HTTP method.
     *
     * @return bool Whether the request object should interpret the '_method' parameter as overriding the HTTP method.
     */
    public static function getMethodParamOverride(): bool
    {
        return self::$methodParamOverride;
    }

    /**
     * Set whether the request object should interpret the '_method' parameter as overriding the HTTP method.
     *
     * @param bool $enabled Whether the request object should interpret the '_method' parameter as overriding the HTTP method.
     */
    public static function setMethodParamOverride(bool $enabled): void
    {
        self::$methodParamOverride = $enabled;
    }

    /**
     * Retrieves a value from the $_COOKIE superglobal.
     *
     * If `$key` is `null`, returns the entire $_COOKIE array.
     *
     * @param string|null $k The key to retrieve from $_COOKIE.
     * @return mixed The value associated with the key or the entire $_COOKIE array if `$key` is `null`.
     */
    public function cookie(?string $k = null): mixed
    {
        return $this->fetch($this->cookieCollection(), $k);
    }

    /**
     * Whether the request expects JSON content in response.
     *
     * This method checks if the 'Accept' header contains 'application/json' or
     * if the request is an AJAX request (by checking the 'X-Requested-With' header).
     *
     * @return bool Whether request expects JSON content in response.
     */
    public function expectsJson(): bool
    {
        return str_contains($this->getHeaderLine('Accept'), 'json') || $this->isAjax();
    }

    /**
     * Whether the request expects XML content in response.
     *
     * @return bool Whether request expects XML content in response.
     */
    public function expectsXml(): bool
    {
        return str_contains($this->getHeaderLine('Accept'), 'xml');
    }

    /**
     * Retrieves an uploaded file or all uploaded files in the collection.
     *
     * If `$key` is `null`, returns all uploaded files in the collection.
     * Otherwise, returns the uploaded file associated with the given key.
     *
     * @param string|null $key Key of the uploaded file to retrieve, or null to get all uploaded files.
     * @return UploadedFile|array<mixed>|null UploadedFile instance if found, null otherwise, or all uploaded files if `$key` is null.
     */
    public function file(?string $key = null): UploadedFile|array|null
    {
        $coll = $this->files();

        $result = $key === null ? $coll->all() : $coll->get($key);

        return \is_array($result) || $result instanceof UploadedFile ? $result : null;
    }

    /**
     * Retrieves the collection of uploaded files.
     *
     * @return UploadedFileCollection An immutable collection of uploaded files.
     */
    public function files(): UploadedFileCollection
    {
        return $this->getUploadedFilesCollection();
    }

    /**
     * Retrieve an attribute from the request.
     *
     * If the attribute does not exist, returns the default value instead of throwing an exception.
     *
     * @param string $name Attribute name
     * @param mixed $default Default value if attribute does not exist
     * @return mixed The attribute value or the default value if it does not exist
     */
    public function getAttribute($name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * Retrieves the attributes associated with the request.
     *
     * @return array<string, mixed> A key-value map of attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Retrieve cookies.
     *
     * Returns cookies sent in the request.
     * The data IS NOT filtered in any way.
     *
     * @return array<string, mixed> Cookies as an associative array.
     */
    public function getCookieParams(): array
    {
        return $this->cookie;
    }

    /**
     * Return the effective HTTP method used (HEAD/GET/POST/PUT/DELETE/PATCH/TRACE/OPTIONS/REPORT/SEARCH).
     * - HEAD will be transformed to GET.
     * - POST might be transformed to the overridden method (if set).
     * - Other non-standard HTTP methods will be returned as-is.
     * - This method is idempotent.
     *
     * @return string The effective HTTP method used.
     */
    public function getEffectiveMethod(): string
    {
        if ($this->effectiveMethod !== null) {
            return $this->effectiveMethod;
        }
        $verb = HttpMethodEnum::normalize($this->method);
        if (!in_array($verb, self::VALID, true)) {
            return $this->effectiveMethod = $verb;          // REPORT / SEARCH …
        }

        return $this->effectiveMethod = match ($verb) {
            HttpMethodEnum::HEAD->value => HttpMethodEnum::GET->value,
            HttpMethodEnum::POST->value => $this->methodOverride() ?? HttpMethodEnum::POST->value,
            default => $verb,
        };
    }

    /**
     * Retrieves the HTTP method (GET, POST, PUT, DELETE, OPTIONS, etc.)
     * that this request was created with.
     *
     * @return string The HTTP method (e.g. "GET", "POST", etc.)
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Returns the parsed body of the request.
     *
     * The parsed body is a key-value pair of the request body.
     * If the request body is JSON, the parsed body is an object.
     * If the request body is a form, the parsed body is an array.
     * If the request body is empty, the parsed body is null.
     *
     * @return array|null|object The parsed body of the request
     */
    /** @return array<mixed>|object|null */
    public function getParsedBody(): array|object|null
    {
        return $this->parsed;
    }

    /**
     * Return the query string parameters as an associative array.
     *
     * This method returns the query string parameters as an associative array.
     * The array keys are the parameter names, and the array values are the parameter values.
     *
     * @return array<string, mixed> The query string parameters as an associative array.
     */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    /**
     * Retrieve the request target as a string.
     *
     * If the request target was explicitly set, return that value.
     * Otherwise, return the path and query string of the Uri.
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }
        $t = $this->uri->getPath() ?: '/';
        $q = $this->uri->getQuery();
        if ($q !== '') {
            $t .= '?' . $q;
        }

        return $t;
    }

    /* ======== 5.  PSR-7 ServerRequestInterface ========================= */

    /**
     * Retrieves a copy of the $_SERVER superglobal.
     *
     * If the instance does not wrap a specific request instance, this method MUST return an empty array.
     *
     * @return array<string, mixed> A copy of the $_SERVER values.
     *
     * @see https://www.php.net/manual/en/reserved.variables.server.php
     */
    public function getServerParams(): array
    {
        return $this->server;
    }

    /**
     * Retrieves an array of uploaded files, each being an instance of
     * UploadedFile.
     *
     * The array keys are the names of the fields in the $_FILES superglobal,
     * and the values are UploadedFile instances.
     *
     * This method is immutable, meaning it will always return the same
     * array of uploaded files.
     *
     * @return array<string, UploadedFile|array<mixed>> An array of uploaded files.
     */
    public function getUploadedFiles(): array
    {
        if ($this->filesHydrated !== null) {
            return $this->filesHydrated;
        }

        return $this->filesHydrated = UploadedFilesNormalizer::normalise($this->filesSpec);
    }

    /**
     * Returns the collection of uploaded files.
     *
     * The collection is an immutable collection of uploaded files where
     * each key is the name of the field and the value is either an
     * UploadedFile or an array of UploadedFile objects.
     *
     * @return UploadedFileCollection An immutable collection of uploaded files.
     */
    public function getUploadedFilesCollection(): UploadedFileCollection
    {
        return $this->filesColl ??= new UploadedFileCollection($this->getUploadedFiles());
    }

    /**
     * Retrieves the Uri object of the request.
     *
     * @return Uri The Uri object of the request.
     */
    public function getUri(): Uri
    {
        return $this->uri;
    }

    /**
     * Return a RequestHeaders instance.
     *
     * If a headers instance was already set using withHeaders(), return that instance.
     * Otherwise, create a new instance with the current request object.
     */
    public function headers(): RequestHeaders
    {
        return $this->hdrFacade ??= new RequestHeaders($this);
    }

    /**
     * Determine if the request is an AJAX request.
     *
     * This method checks the existence of the `X-Requested-With` header
     * and its value being equal to `XMLHttpRequest`. This header is
     * typically sent by JavaScript libraries like jQuery.
     *
     * @return bool true if the request is an AJAX request, false otherwise
     */
    public function isAjax(): bool
    {
        $hdr = $this->server('HTTP_X_REQUESTED_WITH')
            ?: $this->getHeaderLine('X-Requested-With');

        return \is_string($hdr) && strcasecmp($hdr, 'xmlhttprequest') === 0;
    }

    /**
     * Retrieve the parsed JSON from the request body.
     * If the request body was not JSON, an empty Collection is returned.
     * If the key is null, the entire parsed JSON is returned as a Collection.
     * If the key is not null, the value associated with that key is returned,
     * or null if the key was not found in the parsed JSON.
     *
     * @param string|null $key The key to retrieve from the parsed JSON.
     * @return mixed The value associated with the key, or the entire parsed JSON.
     */
    public function parsedJson(?string $key = null): mixed
    {
        if (!$this->jsonCol) {
            $ct = $this->getHeaderLine('Content-Type');
            if (preg_match('#application/(.+\+)?json#i', $ct)) {
                $arr = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
                $this->jsonCol = new Collection((array) $arr);
            } else {
                $this->jsonCol = new Collection([]);
            }
        }

        return $this->jsonCol->isEmpty() ? $this->post($key) : $this->fetch($this->jsonCol, $key);
    }

    /**
     * Retrieve the parsed XML from the request body.
     * If the request body was not XML, an empty Collection is returned.
     * If the key is null, the entire parsed XML is returned as a Collection.
     * If the key is not null, the value associated with that key is returned,
     * or null if the key was not found in the parsed XML.
     *
     * @param string|null $key The key to retrieve from the parsed XML.
     * @return mixed The value associated with the key, or the entire parsed XML.
     */
    public function parsedXml(?string $key = null): mixed
    {
        if (!$this->xmlCol) {
            $ct = $this->getHeaderLine('Content-Type');
            if (preg_match('#(application|text)/xml#i', $ct)) {
                $xml = simplexml_load_string(
                    $this->raw(),
                    'SimpleXMLElement',
                    LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
                ) ?: null;
                $encoded = $xml ? json_encode($xml) : false;
                $arr = \is_string($encoded) ? json_decode($encoded, true) : [];
                $this->xmlCol = new Collection((array) $arr);
            } else {
                $this->xmlCol = new Collection([]);
            }
        }

        return $this->xmlCol->isEmpty() ? $this->post($key) : $this->fetch($this->xmlCol, $key);
    }

    /**
     * Retrieves a value from the $_POST superglobal.
     *
     * If `$key` is `null`, returns the entire $_POST array.
     *
     * @param string|null $k the key to retrieve from $_POST
     * @return mixed the value from $_POST or null if not found
     */
    public function post(?string $k = null): mixed
    {
        return $this->fetch($this->postCollection(), $k);
    }

    /**
     * Retrieves a value from the $_GET superglobal.
     *
     * If `$key` is `null`, returns the entire $_GET array.
     *
     * @param string|null $k The key to retrieve from $_GET.
     * @return mixed The value associated with the key or the entire $_GET array if `$key` is `null`.
     */
    public function query(?string $k = null): mixed
    {
        return $this->fetch($this->queryCollection(), $k);
    }

    /**
     * Return the raw request body as a string.
     *
     * If the request body was parsed successfully (e.g. JSON, form data),
     * the parsed body is returned instead of the raw body.
     *
     * @return string The raw request body as a string.
     */
    public function raw(): string
    {
        return $this->rawBody ??= (string) $this->body;
    }

    /**
     * Retrieves a value from the $_SERVER superglobal.
     *
     * If `$key` is `null`, returns the entire $_SERVER array.
     *
     * @param string|null $k The key to retrieve from $_SERVER.
     * @return mixed The value associated with the key or the entire $_SERVER array if `$key` is `null`.
     */
    public function server(?string $k = null): mixed
    {
        return $this->fetch($this->serverCollection(), $k);
    }

    /**
     * Create a new instance with the specified attribute set to the given value.
     *
     * @param string $name Attribute name
     * @param mixed $value Attribute value
     * @return static New instance with the attribute set
     */
    public function withAttribute(string $name, mixed $value): static
    {
        $cl = clone $this;
        $cl->attributes[$name] = $value;

        return $cl;
    }

    /**
     * Create a new instance with multiple attributes applied in a single clone.
     *
     * @param array<string,mixed> $attributes Attribute bag to merge.
     * @return static New instance with attributes set.
     */
    public function withAttributes(array $attributes): static
    {
        if ($attributes === []) {
            return $this;
        }

        $cl = clone $this;
        foreach ($attributes as $name => $value) {
            $cl->attributes[(string) $name] = $value;
        }

        return $cl;
    }

    /**
     * Return an instance with the specified cookies.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated cookies.
     *
     * @param array<string, mixed> $cookies The cookies as an associative array.
     */
    public function withCookieParams(array $cookies): static
    {
        return $this->withVariableMapRefresh(
            static function (self $clone) use ($cookies): void {
                $clone->cookie = $cookies;
                $clone->cookieCol = null;
            },
        );
    }

    /**
     * Returns a new instance with the specified HTTP method.
     *
     * @param string $method HTTP method (e.g. GET, POST, PUT, DELETE, OPTIONS)
     */
    public function withMethod(string $method): static
    {
        $c = clone $this;
        $c->method = HttpMethodEnum::normalize($method);
        $c->effectiveMethod = null;

        return $c;
    }

    /**
     * Clone the request object without the specified attribute.
     *
     * @param string $name The attribute name to remove.
     * @return static The cloned request object without the specified attribute.
     */
    public function withoutAttribute(string $name): static
    {
        $cl = clone $this;
        unset($cl->attributes[$name]);

        return $cl;
    }

    /**
     * Return an instance with the specified parsed body.
     *
     * @param object|array<string, mixed>|null $data Parsed body data to replace the internal value
     * @return static A new instance with the specified parsed body
     *
     * @throws InvalidArgumentException if the parsed body is invalid
     */
    public function withParsedBody(object|array|null $data): static
    {
        return $this->withVariableMapRefresh(
            static function (self $clone) use ($data): void {
                $clone->parsed = $data;
                $clone->postCol = null;
                $clone->jsonCol = null;
                $clone->xmlCol = null;
                $clone->effectiveMethod = null;
            },
        );
    }

    /**
     * Return an instance with the specified query string as the parameters.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated query string.
     *
     * @param array<string, mixed> $query The query string as an associative array.
     */
    public function withQueryParams(array $query): static
    {
        return $this->withVariableMapRefresh(
            static function (self $clone) use ($query): void {
                $clone->query = $query;
                $clone->queryCol = null;
            },
        );
    }

    /**
     * Creates a new instance with the specified request-target.
     *
     * @param string $requestTarget The request-target to use.
     * @return static A new instance with the specified request-target.
     *
     * @throws InvalidArgumentException If the request-target contains whitespace.
     */
    public function withRequestTarget(string $requestTarget): static
    {
        if (preg_match('#\s#', $requestTarget)) {
            throw new InvalidArgumentException('Whitespace in request-target');
        }
        $c = clone $this;
        $c->requestTarget = $requestTarget;

        return $c;
    }

    /**
     * Creates a new instance of the request with the given uploaded files.
     *
     * Does not mutate the current instance.
     *
     * @param array<string, mixed> $uploadedFiles $_FILES-style specification array.
     */
    public function withUploadedFiles(array $uploadedFiles): static
    {
        $cl = clone $this;
        $cl->filesSpec = $uploadedFiles;
        $cl->filesHydrated = null;
        $cl->filesColl = null;

        return $cl;
    }

    /**
     * Return a new instance with the specified URI, optionally preserving the original Host header.
     *
     * @param Uri $uri The new URI.
     * @param bool $preserveHost If true, the original Host header will be preserved.
     * @return static A new instance with the specified URI.
     */
    public function withUri(Uri $uri, bool $preserveHost = false): static
    {
        $c = clone $this;
        $c->uri = $uri;
        if (!$preserveHost) {
            $c->headers['Host'] = $uri->getHost()
                ? [$uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '')]
                : [];
        } elseif ($uri->getHost() && !$c->hasHeader('Host')) {
            $c->headers['Host'] = [$uri->getHost()];
        }

        return $c;
    }

    /**
     * Attach query string and cookie parameters to the request.
     *
     * @template T of self
     * @param T $req The request object.
     * @param Uri $uri The URI object.
     * @return T The request object with the query string and cookie parameters attached.
     */
    private static function attachQueryAndCookies(self $req, Uri $uri): self
    {
        parse_str($uri->getQuery(), $qs);

        return $req
            ->withQueryParams(self::mixedMap($qs))
            ->withCookieParams(self::mixedMap($_COOKIE));
    }

    /**
     * Detects the HTTP protocol version from the given server parameters.
     *
     * This function extracts the HTTP protocol version from the
     * SERVER_PROTOCOL key in the given server parameters.
     *
     * If the SERVER_PROTOCOL key is not present, or does not start with
     * 'HTTP/', the function returns '1.1' as the HTTP protocol version.
     *
     * Otherwise, it returns the version part of the SERVER_PROTOCOL key,
     * which is the substring starting from the 5th character of the key value.
     *
     * @param array<string, mixed> $srv The server parameters, typically from $_SERVER.
     * @return string The detected HTTP protocol version.
     */
    private static function detectHttpVersion(array $srv): string
    {
        $proto = self::serverString($srv, 'SERVER_PROTOCOL');

        return str_starts_with($proto, 'HTTP/') ? substr($proto, 5) : '1.1';
    }

    /**
     * Replaces the request headers with an immutable HeaderBag.
     *
     * Called once when creating a new ServerRequest from globals.
     *
     * @template T of self
     * @param T $req
     * @return T The request object with the replaced headers.
     */
    private static function importHeadersOnce(self $req): self
    {
        $bag = new RequestHeaders($req)->all();
        $req->headers = $bag->all();   // protected prop on parent; same class context

        return $req;
    }

    /**
     * Attempt to parse the body of a non-POST request as URL-encoded
     * form data.
     *
     * If the request method is PUT, PATCH or DELETE and the Content-Type
     * header is application/x-www-form-urlencoded, the body is parsed and
     * attached to the request as a parsed body.
     *
     * @template T of self
     * @param T $req
     * @return T The request object with the parsed body, or the original
     *           request object if the body could not be parsed.
     */
    private static function maybeParseUrlEncodedForNonPost(self $req, Stream $body): self
    {
        if (
            in_array($req->method, [HttpMethodEnum::PUT->value, HttpMethodEnum::PATCH->value, HttpMethodEnum::DELETE->value], true)
            && str_contains(strtolower($req->getHeaderLine('Content-Type')), MediaTypeEnum::FORM_URLENCODED->value)
        ) {
            parse_str((string) $body, $form);
            $req = $req->withParsedBody(self::mixedMap($form));
        }

        return $req;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function mixedMap(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                continue;
            }
            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * Opens a stream for reading from the current request body.
     *
     * This function first tries to open a stream for reading from the
     * 'php://input' stream, which represents the request body.
     * If this fails, it will fall back to opening a stream for reading
     * from the 'php://temp' stream, which represents the temporary file
     * stream.
     *
     * @return Stream A new Stream object representing the request body.
     */
    private static function openInputStream(): Stream
    {
        $in = fopen('php://input', 'rb') ?: fopen('php://temp', 'rb');

        return new Stream($in);
    }

    /**
     * @param array<mixed> $server
     * @return array<string, mixed>
     */
    private static function serverMap(array $server): array
    {
        $out = [];
        foreach ($server as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function serverString(array $server, string $key, string $default = ''): string
    {
        $value = $server[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /**
     * Lazily builds a single variable map for the lifetime of the request.
     * It uses the determined variable order to construct the map.
     * If the map is already built, it returns immediately.
     * The map is built by iterating over the variable order and
     * adding the corresponding values from the source tables ($this->query,
     * $this->parsed, $this->cookie, $this->server, and $_ENV).
     * The map is then stored in the $this->varMap property.
     * Additionally, the $this->checkEnv flag is set if $_ENV is present
     * in the variable order.
     */
    private function buildVariableMap(): void
    {
        if ($this->varMap !== null) {
            return;           // hot-path: one pointer read
        }

        /** Cache the resolved order for the lifetime of the PHP process */
        /** @var list<string>|null $SEQ */
        static $SEQ = null;
        $seq = $SEQ ??= $this->determineVariableOrder();

        // Hot-path source table (no ENV yet – handled inline)
        $src = [
            'G' => $this->query,
            'P' => \is_array($this->parsed) ? self::mixedMap($this->parsed) : [],
            'C' => $this->cookie,
            'S' => $this->server,
        ];

        $map = [];
        foreach ($seq as $ch) {
            if ($ch === 'E') {           // defer $_ENV until really requested
                $map += self::mixedMap($_ENV);

                continue;
            }
            if (isset($src[$ch])) {
                $map += $src[$ch];       // “+” keeps earlier values (correct precedence)
            }
        }

        $this->varMap = $map;
        $this->checkEnv = in_array('E', $seq, true);
    }

    /**
     * Get or build cookie collection cache.
     */
    private function cookieCollection(): Collection
    {
        return $this->cookieCol ??= new Collection($this->cookie);
    }

    /**
     * Determines the order of variables for the lifetime of the PHP process.
     * Reads 'variables_order' and 'request_order' from ini_get() and returns an array
     * of characters that represent the order of variables (G: GET, P: POST, C: COOKIE, S: SERVER, E: ENV).
     * If 'variables_order' is not set, defaults to 'EGPCS'.
     * If 'request_order' is not set, defaults to an empty string.
     * If 'request_order' contains any of G, P, C, the corresponding characters are removed from the order.
     * The remaining characters from 'request_order' are inserted at the position of E in the order.
     */
    /**
     * @return list<string>
     */
    private function determineVariableOrder(): array
    {
        $vars = strtoupper((string) preg_replace('/[^EGPCS]/', '', ini_get('variables_order') ?: 'EGPCS'));
        $req = strtoupper((string) preg_replace('/[^GPC]/', '', ini_get('request_order') ?: ''));

        $seq = str_split($vars);
        if ($req !== '') {
            $seq = array_values(array_diff($seq, ['G', 'P', 'C']));
            $anchor = array_search('E', $seq, true);
            $insert = $anchor === false ? 0 : $anchor + 1;
            foreach (array_reverse(str_split($req)) as $ch) {
                array_splice($seq, $insert, 0, $ch);
            }
        }

        return $seq;
    }

    /**
     * Fetches a value from the collection.
     *
     * If `$k` is null, the entire collection is returned.
     * Otherwise, the value associated with the key `$k` is returned,
     * or null if the key does not exist in the collection.
     *
     * @param Collection $c the collection to fetch from
     * @param string|null $k the key to fetch
     * @return mixed the value associated with the key, or null if the key does not exist
     */
    private function fetch(Collection $c, ?string $k): mixed
    {
        return $k === null ? $c : ($c->$k ?? null);
    }

    /**
     * Returns true if the request is a POST form submission.
     *
     * A request is considered a form submission if its method is POST and its
     * Content-Type header is either application/x-www-form-urlencoded or
     * multipart/form-data.
     *
     * @return bool True if the request is a form submission, false otherwise.
     */
    private function isFormPost(): bool
    {
        if (HttpMethodEnum::normalize($this->method) !== HttpMethodEnum::POST->value) {
            return false;
        }

        return HttpUtils::isFormContentType($this->getHeaderLine('Content-Type'));
    }

    /**
     * Detects the overridden HTTP method, if any.
     *
     * This method checks for both header-based override and form parameter `_method` override.
     *
     * Header-based override is always honored, while form parameter override is only honored
     * when `methodParamOverride` is set to true and the current request is a POST form submission.
     *
     * @return string|null The overridden HTTP method, if any; null otherwise.
     */
    private function methodOverride(): ?string
    {
        // 1) Header-based override (always honored)
        $hdr = $this->getHeaderLine('X-HTTP-Method-Override')
            ?: $this->getHeaderLine('HTTP-Method-Override');

        if ($hdr !== '') {
            $cand = HttpMethodEnum::normalize($hdr);

            return in_array($cand, self::VALID, true) ? $cand : null;
        }

        // 2) Form parameter `_method` is gated + only for POST form submissions
        if (self::$methodParamOverride && $this->isFormPost()) {
            $override = $this->post('_method');
            if (!\is_string($override)) {
                return null;
            }
            $cand = HttpMethodEnum::normalize($override);

            return in_array($cand, self::VALID, true) ? $cand : null;
        }

        return null;
    }

    /**
     * Get or build parsed-body collection cache.
     */
    private function postCollection(): Collection
    {
        return $this->postCol ??= new Collection(is_array($this->parsed) ? $this->parsed : []);
    }

    /**
     * Get or build query collection cache.
     */
    private function queryCollection(): Collection
    {
        return $this->queryCol ??= new Collection($this->query);
    }

    /**
     * Get or build server params collection cache.
     */
    private function serverCollection(): Collection
    {
        return $this->serverCol ??= new Collection($this->server);
    }

    /**
     * @param callable(self):void $mutate
     */
    private function withVariableMapRefresh(callable $mutate): static
    {
        $clone = clone $this;
        $mutate($clone);
        $clone->varMap = null;
        $clone->checkEnv = false;

        return $clone;
    }
}
