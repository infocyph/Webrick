<?php

/**
 * Webrick - HTTP method enumeration and helpers.
 *
 * Defines a comprehensive set of HTTP verbs, including core methods, CDN/cache
 * extensions, and WebDAV methods. Provides helper predicates to determine
 * safety, idempotency, and whether a request body is allowed, along with
 * case-insensitive parsing utilities.
 *
 * @package Infocyph\Webrick\Constants
 */

namespace Infocyph\Webrick\Constants;

/**
 * HTTP methods as string-backed enum with convenience helpers.
 *
 * Includes:
 * - Core methods (GET, POST, etc.)
 * - CDN/cache methods (PURGE, BAN)
 * - WebDAV methods (PROPFIND, MKCOL, etc.)
 */
enum HttpMethodEnum: string
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
     * Safe methods are intended only for retrieval and must not have side effects.
     *
     * @return bool True if the method is safe; false otherwise.
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
     * Determine if the HTTP method is idempotent (repeatable without additional effects).
     *
     * Idempotent methods can be called multiple times producing the same outcome,
     * as defined by the HTTP specification.
     *
     * @return bool True if the method is idempotent; false otherwise.
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
     * Determine if the HTTP method allows a request body (per common practice).
     *
     * Note: This reflects widely implemented behavior and interoperability concerns,
     * not strictly the RFC’s allowance for a message body on any request.
     *
     * @return bool True if the method commonly allows a request body; false otherwise.
     */
    public function allowsBody(): bool
    {
        return !in_array($this, [self::TRACE, self::HEAD, self::DELETE, self::CONNECT], true);
    }

    /**
     * Determine if a request body is allowed according to RFC 9110.
     *
     * RFC 9110 only explicitly forbids a body for TRACE; other methods may have a body.
     *
     * @return bool True if the method allows a request body; false otherwise.
     *
     * @see https://www.rfc-editor.org/rfc/rfc9110#name-request-method
     */
    public function specAllowsBody(): bool
    {
        return $this !== self::TRACE; // RFC 9110
    }

    /**
     * Case-insensitive attempt to resolve an HTTP method from a string.
     *
     * Equivalent to {@see self::tryFrom()} after normalizing to upper-case.
     *
     * @param string $verb The HTTP method to resolve.
     *
     * @return self|null The resolved HTTP method, or null if not supported.
     */
    public static function tryFromString(string $verb): ?self
    {
        return self::tryFrom(strtoupper($verb));
    }

    /**
     * Case-insensitive resolution of an HTTP method from a string or exception on failure.
     *
     * Equivalent to {@see self::tryFromString()} but throws on unsupported input.
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
     * Get all supported HTTP methods.
     *
     * The list is cached statically for repeated calls.
     *
     * @return array<int,self> Ordered list of all enum cases.
     */
    public static function all(): array
    {
        static $cache = null;
        return $cache ??= array_values(self::cases());
    }
}
