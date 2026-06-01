<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request;

use ArrayAccess;
use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Http\Csrf;
use Infocyph\Webrick\Request\Http\EndUser;
use Infocyph\Webrick\Request\Psr7\ServerRequest;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * @implements ArrayAccess<string, mixed>
 */
class Request extends ServerRequest implements ArrayAccess, JsonSerializable, Stringable
{
    use MacroMix;

    public const HEADER_FORWARDED = 0b10000;

    public const HEADER_X_FORWARDED_FOR = 0b00001;

    public const HEADER_X_FORWARDED_HOST = 0b00010;

    public const HEADER_X_FORWARDED_PORT = 0b01000;

    public const HEADER_X_FORWARDED_PROTO = 0b00100;

    private static int $trustedHeaderFlags
        = self::HEADER_X_FORWARDED_FOR
        | self::HEADER_X_FORWARDED_HOST
        | self::HEADER_X_FORWARDED_PROTO
        | self::HEADER_X_FORWARDED_PORT
        | self::HEADER_FORWARDED;

    /** @var array<string, mixed>|null */
    private ?array $cachedAll = null;

    private ?string $cachedLocale = null;

    /** @var list<string>|null */
    private ?array $cachedSegments = null;

    /**
     * Returns a JSON string representation of the request data.
     *
     * The JSON encode options are set to JSON_UNESCAPED_UNICODE and JSON_THROW_ON_ERROR.
     *
     * @return string A JSON string representation of the request data.
     */
    public function __toString(): string
    {
        return json_encode($this->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Create a fake Request object from the given parameters.
     *
     * This method is useful for unit testing and mocking.
     *
     * @param array<string, mixed> $query Query parameters to set (e.g. `?foo=bar`).
     * @param array<string, mixed> $post Parsed request body to set (e.g. JSON, XML, etc.).
     * @param array<string, string|list<string>> $headers Headers to set (e.g. `Content-Type: application/json`).
     * @param string $method HTTP method to set (e.g. `GET`, `POST`, etc.).
     * @param string $uri URI to set (e.g. `/foo/bar`).
     * @return self A new Request object with the given parameters.
     */
    public static function fake(
        array $query = [],
        array $post = [],
        array $headers = [],
        string $method = HttpMethodEnum::GET->value,
        string $uri = '/',
    ): self {
        return new self(HttpMethodEnum::normalize($method), Uri::from($uri), self::serverMap($_SERVER), $headers)
            ->withQueryParams($query)
            ->withParsedBody($post);
    }

    /**
     * Create a new Request instance from the $_SERVER superglobal.
     *
     * This method is a shortcut to `createFromGlobals()`.
     *
     * @return self A new Request instance.
     */
    public static function fromGlobals(): self
    {
        return self::fromServerRequest(ServerRequest::createFromGlobals());
    }

    /**
     * Retrieves the trusted proxy headers mask set by `setTrustedProxies`.
     *
     * This is a static method that returns the trusted proxy headers mask
     * set by `setTrustedProxies`. The mask is a bitwise OR of the
     * `HEADER_X_FORWARDED_*` constants.
     *
     * @return int The trusted proxy headers mask.
     */
    public static function getProxyHeaderFlags(): int
    {
        return self::$trustedHeaderFlags;
    }

    /**
     * Sets the trusted proxies and header flags for EndUser.
     *
     * @param list<string> $cidrs The trusted proxies to set.
     * @param int|null $headerFlags The trusted proxy headers mask to set.
     *                              This is a bitwise OR of the `HEADER_X_FORWARDED_*` constants.
     *                              If null, the trusted proxy headers mask is not changed.
     */
    public static function setTrustedProxies(array $cidrs, ?int $headerFlags = null): void
    {
        EndUser::setTrustedProxies($cidrs);
        if ($headerFlags !== null) {
            self::$trustedHeaderFlags = $headerFlags;
        }
    }

    /**
     * Returns an associative array containing all request data.
     *
     * The request data is merged from the following sources, in order of priority:
     *   1. JSON payload (if present)
     *   2. Form data (`application/x-www-form-urlencoded` content type)
     *   3. Query parameters (URL query string)
     *
     * The resulting array contains the merged data from all sources.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cachedAll !== null) {
            return $this->cachedAll;
        }

        $query = $this->getQueryParams();
        $parsed = $this->getParsedBody();
        $body = is_array($parsed) ? $parsed : [];
        $data = self::stringMap($body + $query);

        return $this->cachedAll = $data;
    }

    /**
     * Retrieves a boolean value for the given key.
     *
     * If the value is not present or is not a boolean, the given default value is returned.
     *
     * @param string $key The key to retrieve from the request data.
     * @param bool $default The default value to return if the key is not present or is not a boolean.
     * @return bool The boolean value associated with the key or the default value.
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $val = filter_var($this->data($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $val ?? $default;
    }

    /* ========== 7.  Client helpers ================================== */

    /**
     * Returns the canonicalized query string.
     *
     * The canonicalized query string is the normalized form of the query string, where
     * all keys are sorted alphabetically and all values are URL-encoded.
     *
     * @return string The canonicalized query string.
     */
    public function canonicalQuery(): string
    {
        return Uri::normalizeQueryString($this->getUri()->getQuery());
    }

    /**
     * Retrieve a value from the request data using dot-notation.
     * e.g. $request->data('user.name') will return the value of 'name' from the 'user' array.
     * If the key was not found, returns the default value.
     *
     * @param string $dot The dot-notated key to retrieve from the request data.
     * @param mixed $default The default value to return if the key was not found.
     * @return mixed The value associated with the key, or the default value if not found.
     */
    public function data(string $dot, mixed $default = null): mixed
    {
        $segments = explode('.', $dot);
        $value = parent::__get(array_shift($segments));

        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }

        return $value ?? $default;
    }

    /**
     * Resolve a locale from multiple sources; first hit wins.
     *
     * @param array<int,string> $supported
     * @param array<int,string> $sources
     * @return array{0:string,1:string}
     */
    public function detectLocale(
        array $supported,
        string $fallback = 'en',
        array $sources = ['attr', 'route', 'query', 'cookie', 'header', 'default'],
    ): array {
        $supported = array_values(array_unique(array_map(self::normalizeLocale(...), $supported)));
        $fallback = self::normalizeLocale($fallback);

        foreach ($sources as $src) {
            $hit = match ($src) {
                'attr' => $this->resolveLocaleFromAttr($supported),
                'route' => $this->resolveLocaleFromRoute($supported),
                'query' => $this->resolveLocaleFromQuery($supported),
                'cookie' => $this->resolveLocaleFromCookie($supported),
                'header' => $this->resolveLocaleFromHeader($supported, $fallback),
                'default' => $fallback,
                default => null,
            };

            if ($hit !== null) {
                // ensure normalization (resolvers already return normalized strings)
                return [$hit === 'default' ? $fallback : $hit, $src];
            }
        }

        return [$fallback, 'default'];
    }

    /**
     * Returns a new array containing all the key-value pairs from the current request
     * except for the given keys.
     *
     * Useful for validating forms where some fields must have a value.
     *
     * @param string|list<string> $keys The keys to exclude.
     * @return array<string, mixed> The filtered key-value pairs.
     */
    public function except(string|array $keys): array
    {
        return self::stringMap(array_diff_key($this->all(), array_flip(self::stringList($keys))));
    }

    /**
     * Whether the request expects JSON content in response.
     *
     * This method checks if the 'Accept' header contains 'application/json' or
     * if the request is an AJAX request (by checking the 'X-Requested-With' header).
     *
     * @return bool Whether request expects JSON content in response.
     */
    #[\Override]
    public function expectsJson(): bool
    {
        return parent::expectsJson();
    }

    /**
     * Check if the request body contains XML content.
     *
     * This method checks the Content-Type header of the request to see if it contains
     * a MIME type that indicates XML content. If the header is not present, or does not
     * indicate XML content, this method will return false.
     *
     * @return bool Whether the request body contains XML content.
     */
    #[\Override]
    public function expectsXml(): bool
    {
        return parent::expectsXml();
    }

    /**
     * Checks if all of the given data keys have a value.
     * Useful for validating forms where all fields must have a value.
     *
     * @param string|list<string> $keys The keys to check.
     * @return bool True if all keys have a value, false otherwise.
     */
    public function filled(string|array $keys): bool
    {
        foreach (self::stringList($keys) as $k) {
            $v = $this->data($k);
            if ($v === null || $v === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if all of the given data keys have a value.
     * Useful for validating forms where some fields must have a value.
     *
     * @param string|list<string> $keys The keys to check.
     * @return bool True if all keys have a value, false otherwise.
     */
    public function has(string|array $keys): bool
    {
        return array_all(self::stringList($keys), fn($key) => !($this->data($key) === null));
    }

    /**
     * Check if an uploaded file exists in the request.
     *
     * @param string $key Key of the uploaded file to check.
     * @return bool True if the uploaded file exists, false otherwise.
     */
    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    /**
     * Retrieves a header value from the request.
     *
     * If the header is present, its value is returned as a string. If the header
     * is absent, the method returns the default value passed as the second argument.
     *
     * The method is case-insensitive, so the header name can be passed in any case.
     *
     * @param string $name Case-insensitive header name
     * @param string|null $default Default value to return when the header is absent
     * @return string|null Header value or default value when header absent
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $line = $this->getHeaderLine($name);

        return $line !== '' ? $line : $default;
    }

    /**
     * Retrieves a value from the request data for the given key.
     *
     * If the value is not present, the given default value is returned.
     *
     * @param string $key The key to retrieve from the request data.
     * @param mixed $default The default value to return if the key is not present.
     * @return mixed The value associated with the key or the default value if not found.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data($key, $default);
    }

    /**
     * Returns an integer value for the given key. If the value is not present or is not an integer,
     * the given default value is returned.
     *
     * @param string $key The key to retrieve the value for
     * @param int $default The default value to return if the key is not present or is not an integer
     * @return int The integer value for the given key, or the default value
     */
    public function int(string $key, int $default = 0): int
    {
        $v = filter_var($this->data($key), FILTER_VALIDATE_INT);

        return $v !== false ? $v : $default;
    }

    /**
     * Returns the client's public IP address.
     *
     * If `$proxyAware` is true, the method will return the first public IP address
     * in the chain of forwarded IPs (proxy-aware). Otherwise, it will return
     * the client's public IP address without considering any trusted proxies.
     *
     * @param bool $proxyAware Whether to return the first public IP address
     *                         in the chain of forwarded IPs (proxy-aware) or not.
     * @return string|null The client's public IP address, or null if not available.
     */
    public function ip(bool $proxyAware = false): ?string
    {
        $eu = EndUser::from($this);

        return $proxyAware ? $eu->ipViaProxy() : $eu->ipNoProxy();
    }

    /**
     * Check if the request body contains JSON content.
     *
     * This method checks the Content-Type header of the request to see if it contains
     * a MIME type that indicates JSON content. If the header is not present, or does not
     * match the expected pattern, the method returns false.
     *
     * @return bool Whether the request body contains JSON content.
     */
    public function isJson(): bool
    {
        return (bool) preg_match('#(?:application|text)/(?:[^\s;]+\+)?json#i', $this->getHeaderLine('Content-Type'));
    }

    /**
     * Whether the request was made with one of the given HTTP verbs.
     *
     * @param string|list<string> $verbs HTTP verbs to check against (e.g. 'GET', 'POST', ['GET', 'HEAD']).
     * @return bool True if the request was made with one of the given HTTP verbs, false otherwise.
     */
    public function isMethod(string|array $verbs): bool
    {
        $normalized = [];
        foreach (self::stringList($verbs) as $verb) {
            $normalized[] = HttpMethodEnum::normalize($verb);
        }

        return in_array(HttpMethodEnum::normalize($this->getEffectiveMethod()), $normalized, true);
    }

    /**
     * Whether the request was made over HTTPS.
     *
     * @return bool True if the request was made over HTTPS, false otherwise.
     */
    public function isSecure(): bool
    {
        return $this->getUri()->getScheme() === 'https';
    }

    /**
     * Check if the request body contains XML content.
     *
     * This method checks the Content-Type header of the request to see if it contains
     * a MIME type that indicates XML content. If the header is not present, or does not
     * contain a valid MIME type for XML, this method will return false.
     *
     * @return bool True if the request body contains XML content, false otherwise.
     */
    public function isXml(): bool
    {
        return (bool) preg_match('#(?:application|text)/(?:[^\s;]+\+)?xml#i', $this->getHeaderLine('Content-Type'));
    }

    /**
     * Return an associative array that can be used to serialize the request data.
     *
     * @return array<string, mixed> An associative array containing the request data.
     */
    public function jsonSerialize(): array
    {
        return $this->all();
    }

    /**
     * Get the best-match language from the Accept-Language header.
     *
     * @param list<string>|null $supported list of supported languages (e.g. ['en', 'fr', 'bn-BD'])
     * @param string $fallback language to use if no match is found
     * @param bool $cache whether to store the result in an instance variable
     * @return string the best-match language
     *
     * If $supported is null, the method will use the first 5 characters of the header
     * as the best-match language. If $supported is not null, the method will iterate over
     * the list of supported languages and find the best match. If no match is found, the method
     * will return the $fallback language.
     */
    public function locale(
        ?array $supported = null,
        string $fallback = 'en',
        bool $cache = true,
    ): string {
        if ($cache && $supported === null && $this->cachedLocale !== null) {
            return $this->cachedLocale;
        }

        $language = $this->headers()->accept('Accept-Language');
        if ($language === []) {
            return $fallback;
        }

        if ($supported === null) {
            $first = $language[0];

            return $this->cachedLocale = strtolower(substr($first, 0, 5));
        }

        $supported = array_map(static fn(string $l) => strtolower(str_replace('_', '-', $l)), $supported);

        foreach ($language as $lang) {
            $lang = strtolower(str_replace('_', '-', $lang));
            $short = substr($lang, 0, 2);
            if (in_array($lang, $supported, true)) {
                return $this->cachedLocale = $lang;
            }
            if (in_array($short, $supported, true)) {
                return $this->cachedLocale = $short;
            }
        }

        return $fallback;
    }

    /**
     * Verify a CSRF token against the stored value.
     *
     * If the first argument `$token` is given, it will be compared directly
     * against the stored value. Otherwise, the function will extract the
     * CSRF token from the request (in this order: headers/form/query/cookie) and
     * compare it against the stored value.
     *
     * @param string|null $token The CSRF token to compare.
     * @return bool True if the token matches, false otherwise.
     */
    public function matchesCsrfToken(?string $token = null): bool
    {
        return $token !== null
            ? Csrf::matchesValue($token)   // fast-path when caller already has a token
            : Csrf::matches($this);        // extract from request (headers/form/query/cookie)
    }

    /**
     * Merge the given array with the current request data.
     *
     * The given array will overwrite any existing key in the current request data.
     *
     * @param array<string, mixed> $data The array to merge with the current request data.
     * @return self A new instance with the merged request data.
     */
    public function merge(array $data): self
    {
        $parsed = $this->getParsedBody();
        $body = is_array($parsed) ? $parsed : [];

        return $this->withParsedBody(self::stringMap(array_merge($body, $data)));
    }

    /**
     * Determine if the given key(s) is missing from the request data.
     *
     * This method is a shortcut for checking if a key does not exist in the request data.
     * It returns true if the key does not exist, otherwise false.
     * If an array of keys is given, it returns true if any of the keys do not exist, otherwise false.
     *
     * @param string|list<string> $keys Key(s) to check for existence
     * @return bool True if the key(s) is missing, false otherwise
     */
    public function missing(string|array $keys): bool
    {
        return !$this->has($keys);
    }

    /**
     * Check if a given offset exists in the request data.
     *
     * @param mixed $offset The key to check for existence.
     * @return bool True if the offset exists, false otherwise.
     */
    public function offsetExists(mixed $offset): bool
    {
        $key = self::toStringKey($offset);
        if ($key === null) {
            return false;
        }

        return $this->data($key) !== null;
    }

    /**
     * Retrieves a value from the request data by key.
     *
     * @param mixed $offset The key to retrieve from the request data.
     * @return mixed The value associated with the key or null if not found.
     */
    public function offsetGet(mixed $offset): mixed
    {
        $key = self::toStringKey($offset);

        return $key === null ? null : $this->data($key);
    }

    /**
     * Attempts to set a key in the request data will result in a LogicException as the request data is immutable.
     *
     * @param mixed $offset The key to set.
     * @param mixed $value The value to set.
     *
     * @throws \LogicException Always thrown as the request data is immutable.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidArgumentException('Request is immutable');
    }

    /**
     * Attempts to unset a key in the request data will result in a LogicException as the request data is immutable.
     *
     * @param mixed $offset The key to unset.
     *
     * @throws \LogicException Always thrown as the request data is immutable.
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidArgumentException('Request is immutable');
    }

    /**
     * Returns a new array containing all the key-value pairs from the current request
     * except for the given keys.
     *
     * Useful for validating forms where some fields must have a value.
     *
     * @param string|list<string> $keys The keys to exclude from the request.
     * @return array<string, mixed> The filtered request data.
     */
    public function only(string|array $keys): array
    {
        return array_intersect_key($this->all(), array_flip(self::stringList($keys)));
    }

    /**
     * Check if the client prefers any of the given MIME types.
     *
     * @param string[] $mimeTypes ordered list of MIME types the client prefers
     * @return string|null the first matching MIME type (or null if none match)
     */
    public function prefers(array $mimeTypes): ?string
    {
        // The helper already returns the first matching MIME type (or null)
        return new ContentNegotiator(
            $this->headers(),
        )->preferred($mimeTypes);
    }

    /**
     * Replace the entire request data with the given array.
     *
     * @param array<string, mixed> $data The new request data to replace the old one.
     * @return self A new instance with the replaced request data.
     */
    public function replace(array $data): self
    {
        return $this->withParsedBody($data);
    }

    /**
     * Checks if the current request target matches any of the given patterns.
     *
     * The patterns can contain '*' as a wildcard character.
     *
     * @param string|list<string> $patterns One or multiple patterns to match against.
     * @return bool True if the request target matches any of the patterns, false otherwise.
     */
    public function routeIs(string|array $patterns): bool
    {
        $target = $this->getRequestTarget();
        foreach (self::stringList($patterns) as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the value of the segment at the given 1-based index or the
     * given default value if the index is out of bounds.
     *
     * @param int $index 1-based index of the segment to retrieve
     * @param mixed $default Value to return if the index is out of bounds
     * @return string|mixed The value of the segment at the given index or the default value
     */
    public function segment(int $index, mixed $default = null): mixed
    {
        return $this->segments()[$index - 1] ?? $default;
    }

    /**
     * Returns an array of all path segments of the current URI.
     * Segments are split by the '/' character and empty segments are removed.
     * The resulting array is 0-indexed.
     *
     * Example: for the URI "/foo/bar/baz", the segments array will be ["foo", "bar", "baz"]
     *
     * @return list<string> an array of path segments
     */
    public function segments(): array
    {
        return $this->cachedSegments ??= array_values(
            array_filter(
                explode('/', $this->getUri()->getPath()),
                static fn(string $s) => $s !== '',
            ),
        );
    }

    /**
     * Retrieve a string value from the request data.
     *
     * This method is a shortcut for retrieving a string value from the request data.
     * If the key does not exist in the request data, the given default value is returned.
     *
     * @param string $key The key to retrieve from the request data.
     * @param string $default The default value to return if the key does not exist.
     * @return string The string value associated with the key or the default value if the key does not exist.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->data($key);

        return is_string($value) ? $value : $default;
    }

    /**
     * Returns an associative array with the following keys:
     *   - browser: the browser name (e.g. Chrome, Firefox, Safari)
     *   - version: the browser version (e.g. 117, 108.0.1)
     *   - platform: the platform name (e.g. Windows, macOS, Linux)
     *   - engine: the rendering engine name (e.g. Blink, Gecko, WebKit)
     *   - raw: the raw User-Agent string
     *
     * @return array<string, string>
     */
    public function ua(): array
    {
        return EndUser::from($this)->parseUserAgent();
    }

    /**
     * Validate the request data against the given rules.
     *
     * If a "required" rule is specified for a field and that field is not present in the request,
     * an InvalidArgumentException will be thrown.
     *
     * @param array<string, string> $rules an associative array where the keys are the field names and the values are strings containing the rules.
     * @return array<string, mixed> the validated request data with only the fields specified in the rules.
     *
     * @throws InvalidArgumentException if a "required" field is not present in the request.
     */
    public function validate(array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (str_contains($rule, 'required') && !$this->filled($field)) {
                throw new InvalidArgumentException("Field '{$field}' is required");
            }
        }

        return $this->only(array_keys($rules));
    }

    /**
     * Whether the request expects a JSON response.
     *
     * @return bool Whether request expects a JSON response.
     */
    public function wantsJson(): bool
    {
        return $this->expectsJson();
    }

    /**
     * Convenience alias for expectsXml().
     *
     * @return bool Whether the request expects an XML response.
     */
    public function wantsXml(): bool
    {
        return $this->expectsXml();
    }

    /**
     * Return an instance with updated cookies and reset derived caches.
     */
    #[\Override]
    public function withCookieParams(array $cookies): static
    {
        $cl = parent::withCookieParams($cookies);
        $cl->resetDerivedCaches();

        return $cl;
    }

    /**
     * Return an instance with updated parsed body and reset derived caches.
     */
    #[\Override]
    public function withParsedBody(object|array|null $data): static
    {
        $cl = parent::withParsedBody($data);
        $cl->resetDerivedCaches();

        return $cl;
    }

    /**
     * Return an instance with updated query parameters and reset derived caches.
     */
    #[\Override]
    public function withQueryParams(array $query): static
    {
        $cl = parent::withQueryParams($query);
        $cl->resetDerivedCaches();

        return $cl;
    }

    /**
     * Return an instance with updated URI and reset path/locale caches.
     */
    #[\Override]
    public function withUri(Uri $uri, bool $preserveHost = false): static
    {
        $cl = parent::withUri($uri, $preserveHost);
        $cl->resetDerivedCaches();

        return $cl;
    }

    private static function fromServerRequest(ServerRequest $request): self
    {
        $parsedBody = $request->getParsedBody();
        $parsed = is_array($parsedBody) ? self::stringMap($parsedBody) : $parsedBody;

        $new = new self(
            method: $request->getMethod(),
            uri: $request->getUri(),
            server: $request->getServerParams(),
            headers: $request->getHeaders(),
            body: $request->getBody(),
            httpVer: $request->getProtocolVersion(),
            parsed: $parsed,
            files: $request->getUploadedFiles(),
            requestTarget: $request->getRequestTarget(),
        );

        return $new
            ->withQueryParams(self::stringMap($request->getQueryParams()))
            ->withCookieParams(self::stringMap($request->getCookieParams()));
    }

    /** ───── leaf helpers ───── */

    /** Normalize to lowercase BCP47-ish with hyphen, e.g. 'pt_BR' -> 'pt-br'. */
    private static function normalizeLocale(string $l): string
    {
        $l = str_replace('_', '-', trim($l));

        return strtolower($l);
    }

    /**
     * @param array<mixed> $server
     * @return array<string, mixed>
     */
    private static function serverMap(array $server): array
    {
        $result = [];
        foreach ($server as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param string|array<mixed> $value
     * @return list<string>
     */
    private static function stringList(string|array $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function stringMap(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }

    private static function toStringKey(mixed $key): ?string
    {
        if (is_string($key)) {
            return $key;
        }

        if (is_int($key) || is_float($key) || is_bool($key)) {
            return (string) $key;
        }

        return null;
    }

    /** Exact match, then primary-subtag fallback, else null. */
    /**
     * @param list<string> $supported
     */
    private function pickLocale(?string $raw, array $supported): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $cand = self::normalizeLocale($raw);
        if (in_array($cand, $supported, true)) {
            return $cand;
        }
        $primary = substr($cand, 0, 2);

        return in_array($primary, $supported, true) ? $primary : null;
    }

    /**
     * Reset per-instance derived caches after immutable mutations.
     */
    private function resetDerivedCaches(): void
    {
        $this->cachedAll = null;
        $this->cachedLocale = null;
        $this->cachedSegments = null;
    }

    /** ───── resolvers ───── */
    /**
     * @param list<string> $supported
     */
    private function resolveLocaleFromAttr(array $supported): ?string
    {
        $val = $this->getAttribute('locale') ?? $this->getAttribute('lang');

        return is_string($val) ? $this->pickLocale($val, $supported) : null;
    }

    /**
     * @param list<string> $supported
     */
    private function resolveLocaleFromCookie(array $supported): ?string
    {
        $cookieLocale = $this->cookie('locale');
        $cookieLang = $this->cookie('lang');
        $val = is_string($cookieLocale) ? $cookieLocale : (is_string($cookieLang) ? $cookieLang : '');

        return $this->pickLocale($val, $supported);
    }

    /**
     * @param list<string> $supported
     */
    private function resolveLocaleFromHeader(array $supported, string $fallback): ?string
    {
        // reuse existing Accept-Language matcher
        $val = $this->locale($supported, $fallback, true);

        return $this->pickLocale($val, $supported);
    }

    /**
     * @param list<string> $supported
     */
    private function resolveLocaleFromQuery(array $supported): ?string
    {
        $queryLocale = $this->query('locale');
        $queryLang = $this->query('lang');
        $val = is_string($queryLocale) ? $queryLocale : (is_string($queryLang) ? $queryLang : '');

        return $this->pickLocale($val, $supported);
    }

    /**
     * @param list<string> $supported
     */
    private function resolveLocaleFromRoute(array $supported): ?string
    {
        foreach (
            [
                $this->getAttribute('route.params'),
                $this->getAttribute('route'),
                $this->getAttribute('params'),
            ] as $bag
        ) {
            if (is_array($bag)) {
                $val = $bag['locale'] ?? $bag['lang'] ?? null;
                if (is_string($val) && ($hit = $this->pickLocale($val, $supported))) {
                    return $hit;
                }
            }
        }

        return null;
    }
}
