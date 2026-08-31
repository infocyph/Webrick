<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

/** HTTP methods as string-backed enum with convenience helpers. */
enum HttpMethodEnum: string
{
    case BAN = 'BAN';

    case CONNECT = 'CONNECT';

    case COPY = 'COPY';

    case DELETE = 'DELETE';

    case GET = 'GET';

    case HEAD = 'HEAD';

    case LINK = 'LINK';

    case LOCK = 'LOCK';

    case MKCALENDAR = 'MKCALENDAR';

    case MKCOL = 'MKCOL';

    case MOVE = 'MOVE';

    case OPTIONS = 'OPTIONS';

    case PATCH = 'PATCH';

    case POST = 'POST';

    case PROPFIND = 'PROPFIND';

    case PROPPATCH = 'PROPPATCH';

    case PURGE = 'PURGE';

    case PUT = 'PUT';

    case REPORT = 'REPORT';

    case SEARCH = 'SEARCH';

    case TRACE = 'TRACE';

    case UNLINK = 'UNLINK';

    case UNLOCK = 'UNLOCK';

    /** @return array<int,self> */
    public static function all(): array
    {
        return self::cases();
    }

    public static function fromString(string $verb): self
    {
        return self::tryFromString($verb)
            ?? throw new \InvalidArgumentException("Unsupported method: {$verb}");
    }

    /** Known and extension methods share the same canonical uppercase representation. */
    public static function normalize(string $verb): string
    {
        return strtoupper(trim($verb));
    }

    public static function tryFromString(string $verb): ?self
    {
        return self::tryFrom(strtoupper($verb));
    }

    public function allowsBody(): bool
    {
        return !in_array($this, [self::TRACE, self::HEAD, self::DELETE, self::CONNECT], true);
    }

    public function isIdempotent(): bool
    {
        return in_array($this, [self::GET, self::HEAD, self::PUT, self::DELETE, self::OPTIONS, self::TRACE], true);
    }

    public function isSafe(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    public function specAllowsBody(): bool
    {
        return $this !== self::TRACE;
    }
}
