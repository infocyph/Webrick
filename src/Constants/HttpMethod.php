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
     * True for safe reads with *no intended* state change (RFC 9110 &sect;9.2).
     */
    public function isSafe(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /**
     * True for methods that may be repeated without side-effects (RFC 9110 &sect;9.2.3).
     *
     * @return bool
     */
    public function isIdempotent(): bool
    {
        return in_array($this, [self::GET, self::HEAD, self::PUT, self::DELETE, self::OPTIONS, self::TRACE], true);
    }

    /**
     * Whether the method allows sending a request body.
     *
     * Per the HTTP spec, the following methods do not allow a request body:
     *   - TRACE
     *   - HEAD
     *   - DELETE
     *   - CONNECT
     *
     * @return bool
     */
    public function allowsBody(): bool
    {
        return !in_array($this, [self::TRACE, self::HEAD, self::DELETE, self::CONNECT], true);
    }

    /**
     * Whether the HTTP method allows sending a request body according to the HTTP spec (RFC 9110).
     *
     * The only method that does not allow a request body is TRACE.
     *
     * @return bool
     */
    public function specAllowsBody(): bool
    {
        return $this !== self::TRACE; // RFC 9110
    }

    /**
     * Returns an instance of the HTTP method enum case matching the given string, or null if the string does not match any known HTTP method.
     *
     * The string is case-insensitive.
     *
     * @param string $verb The HTTP method verb to match.
     *
     * @return self|null
     */
    public static function tryFromString(string $verb): ?self
    {
        return self::tryFrom(strtoupper($verb));
    }


    /**
     * Returns an instance of the HTTP method enum case matching the given string.
     *
     * @param string $verb The HTTP method verb to match. The string is case-insensitive.
     *
     * @return self
     *
     * @throws \InvalidArgumentException If the given string does not match any known HTTP method.
     */
    public static function fromString(string $verb): self
    {
        return self::tryFromString($verb)
            ?? throw new \InvalidArgumentException("Unsupported method: {$verb}");
    }


    /**
     * Return an array of all the HTTP method enum cases.
     *
     * The returned array is cached for performance reasons.
     *
     * @return array<self>
     */
    public static function all(): array
    {
        static $cache = null;
        return $cache ??= array_values(self::cases());
    }
}
