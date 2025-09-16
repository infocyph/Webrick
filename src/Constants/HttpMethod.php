<?php

namespace Infocyph\Webrick\Constants;

enum HttpMethod: string
{
    /* core */
    case GET = 'GET';
    case HEAD = 'HEAD';
    case POST = 'POST';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';
    case TRACE = 'TRACE';
    case CONNECT = 'CONNECT';
    case PATCH = 'PATCH';

    /* CDN / cache */
    case PURGE = 'PURGE';
    case BAN = 'BAN';

    /* WebDAV */
    case PROPFIND = 'PROPFIND';
    case PROPPATCH = 'PROPPATCH';
    case MKCOL = 'MKCOL';
    case COPY = 'COPY';
    case MOVE = 'MOVE';
    case LOCK = 'LOCK';
    case UNLOCK = 'UNLOCK';
    case REPORT = 'REPORT';
    case MKCALENDAR = 'MKCALENDAR';
    case SEARCH = 'SEARCH';

    /* extras */
    case LINK = 'LINK';
    case UNLINK = 'UNLINK';


    /**
     * Determine if the HTTP method is safe (read-only).
     *
     * @return bool true if the method is safe, false otherwise
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/GET
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/HEAD
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/OPTIONS
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/TRACE
     */
    public function isSafe(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /**
     * Determine if the HTTP method is idempotent (can be called multiple times without side effects).
     *
     * The following HTTP methods are considered idempotent:
     * - GET
     * - HEAD
     * - PUT
     * - DELETE
     * - OPTIONS
     * - TRACE
     *
     * @return bool true if the method is idempotent, false otherwise
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/GET
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/HEAD
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/OPTIONS
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/TRACE
     */
    public function isIdempotent(): bool
    {
        return in_array($this, [self::GET, self::HEAD, self::PUT, self::DELETE, self::OPTIONS, self::TRACE], true);
    }

    /**
     * Determine if the HTTP method allows a request body.
     *
     * The following HTTP methods do not allow a request body:
     * - TRACE
     * - HEAD
     * - DELETE
     * - CONNECT
     *
     * @return bool true if the method allows a request body, false otherwise
     */
    public function allowsBody(): bool
    {
        return !in_array($this, [self::TRACE, self::HEAD, self::DELETE, self::CONNECT], true);
    }

    /**
     * Check if the HTTP method allows a request body according to RFC 9110.
     *
     * @return bool true if the method allows a request body, false otherwise
     *
     * @see https://tools.ietf.org/html/rfc9110#section-5.13
     */
    public function specAllowsBody(): bool
    {
        return $this !== self::TRACE; // RFC 9110
    }

    /**
     * Attempt to resolve a HTTP method from a string.
     *
     * This is a case-insensitive version of {@see self::tryFrom()}.
     *
     * @param string $verb The HTTP method to resolve.
     * @return self|null The resolved HTTP method, or null if not supported.
     */
    public static function tryFromString(string $verb): ?self
    {
        return self::tryFrom(strtoupper($verb));
    }

    /**
     * Attempt to resolve a HTTP method from a string.
     *
     * This is a case-insensitive version of {@see self::tryFrom()}.
     *
     * @param string $verb The HTTP method to resolve.
     *
     * @return self The resolved HTTP method.
     *
     * @throws \InvalidArgumentException If the method is not supported.
     */
    public static function fromString(string $verb): self
    {
        return self::tryFromString($verb)
            ?? throw new \InvalidArgumentException("Unsupported method: {$verb}");
    }

    /**
     * Get all HTTP methods.
     *
     * This method returns an array of all supported HTTP methods.
     * The array will contain all the constants defined in this class.
     *
     * @return array<int, self> An array of all supported HTTP methods.
     */
    public static function all(): array
    {
        static $cache = null;
        return $cache ??= array_values(self::cases());
    }
}
