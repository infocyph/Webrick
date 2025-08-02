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

    /* ───────────────────────── helpers ───────────────────────── */

    /** True for safe reads with *no intended* state change (RFC 9110 §9.2). */
    public function isSafe(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /** True for methods that may be repeated without side-effects (§9.2.3). */
    public function isIdempotent(): bool
    {
        return in_array($this, [self::GET, self::HEAD, self::PUT, self::DELETE, self::OPTIONS, self::TRACE], true);
    }

    /** Does the spec allow a request body for this verb? */
    public function allowsBody(): bool
    {
        return in_array($this, [self::POST, self::PUT, self::PATCH, self::PROPPATCH, self::COPY, self::MOVE, self::LOCK, self::UNLOCK, self::REPORT], true);
    }

    /* -------- convenience factories -------- */

    /** Case-insensitive parse with graceful failure. */
    public static function tryFromString(string $verb): ?self
    {
        return self::tryFrom(strtoupper($verb));
    }

    /** Hard parse with descriptive exception. */
    public static function fromString(string $verb): self
    {
        return self::tryFromString($verb)
            ?? throw new \InvalidArgumentException("Unsupported method: {$verb}");
    }

    /** List-all helper for reflection-free loops. */
    public static function all(): array
    {
        static $cache = null;
        return $cache ??= array_values(self::cases());
    }
}
