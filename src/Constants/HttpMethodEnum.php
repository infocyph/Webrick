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

    /**
     * Known Webrick methods are canonicalized to their registered uppercase form.
     * Unknown extension methods preserve their case because HTTP method tokens are case-sensitive.
     */
    public static function normalize(string $verb): string
    {
        $verb = trim($verb);
        if ($verb === '') {
            throw new \InvalidArgumentException('HTTP method must not be empty.');
        }
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $verb) !== 1) {
            throw new \InvalidArgumentException('Invalid HTTP method token.');
        }

        $known = self::tryFrom(strtoupper($verb));

        return $known instanceof self ? $known->value : $verb;
    }

    public static function tryFromString(string $verb): ?self
    {
        $verb = trim($verb);
        if ($verb === '') {
            return null;
        }

        return self::tryFrom(strtoupper(self::normalize($verb)));
    }

    public function allowsBody(): bool
    {
        return $this->specAllowsBody();
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
