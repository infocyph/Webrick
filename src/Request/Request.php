<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request;

use ArrayAccess;
use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Http\{ContentNegotiator, Csrf, EndUser};
use Infocyph\Webrick\Request\Psr7\ServerRequest;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

class Request extends ServerRequest implements ArrayAccess, JsonSerializable, Stringable
{
    use MacroMix;

    public const HEADER_X_FORWARDED_FOR = 0b00001;
    public const HEADER_X_FORWARDED_HOST = 0b00010;
    public const HEADER_X_FORWARDED_PROTO = 0b00100;
    public const HEADER_X_FORWARDED_PORT = 0b01000;
    public const HEADER_FORWARDED = 0b10000;

    /** @var int */
    private static int $trustedHeaderFlags =
        self::HEADER_X_FORWARDED_FOR
        | self::HEADER_X_FORWARDED_HOST
        | self::HEADER_X_FORWARDED_PROTO
        | self::HEADER_X_FORWARDED_PORT
        | self::HEADER_FORWARDED;

    /* ========== 0.  Factory shortcuts  =============================== */

    /** Fake request for unit tests */
    public static function fake(
        array $query = [],
        array $post = [],
        array $headers = [],
        string $method = 'GET',
        string $uri = '/',
    ): self {
        return new self($method, Uri::from($uri), $_SERVER, $headers)
            ->withQueryParams($query)
            ->withParsedBody($post);
    }

    public static function fromGlobals(): static
    {
        return static::createFromGlobals();
    }

    /** Forward trusted-proxy list to EndUser helper */
    public static function setTrustedProxies(array $cidrs, ?int $headerFlags = null): void
    {
        EndUser::setTrustedProxies($cidrs);
        if ($headerFlags !== null) {
            self::$trustedHeaderFlags = $headerFlags;
        }
    }

    public static function getProxyHeaderFlags(): int
    {
        return self::$trustedHeaderFlags;
    }

    /* ========== 1.  Basic accessors  ================================= */

    private ?array $cachedAll = null;
    private ?array $cachedSegments = null;
    private ?string $cachedLocale = null;

    public function all(): array
    {
        if ($this->cachedAll !== null) {
            return $this->cachedAll;
        }

        // JSON wins over form-data (Laravel semantics)
        $data = $this->parsedJson()?->all()
            ?: ($this->post()?->all() + $this->query()?->all());

        return $this->cachedAll = $data;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data($key, $default);
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $val = filter_var($this->data($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $val ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = filter_var($this->data($key), FILTER_VALIDATE_INT);
        return $v !== false ? $v : $default;
    }

    /* ========== 2.  URI helpers  ==================================== */

    public function segments(): array
    {
        return $this->cachedSegments ??= array_values(
            array_filter(
                explode('/', $this->getUri()->getPath()),
                static fn(string $s) => $s !== '',
            ),
        );
    }

    public function segment(int $index, mixed $default = null): ?string
    {
        return $this->segments()[$index - 1] ?? $default;
    }

    public function routeIs(string|array $patterns): bool
    {
        $target = $this->getRequestTarget();
        foreach ((array)$patterns as $p) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($p, '#')) . '$#';
            if (preg_match($regex, $target)) {
                return true;
            }
        }
        return false;
    }

    public function isMethod(string|array $verbs): bool
    {
        return in_array($this->getEffectiveMethod(), array_map('strtoupper', (array)$verbs), true);
    }

    public function isSecure(): bool
    {
        return $this->getUri()->getScheme() === 'https';
    }

    /* ========== 3.  CSRF helper ===================================== */

    public function matchesCsrfToken(?string $token = null): bool
    {
        return $token !== null
            ? Csrf::matchesValue($token)   // fast-path when caller already has a token
            : Csrf::matches($this);        // extract from request (headers/form/query/cookie)
    }

    /* ========== 4.  Data helpers ==================================== */

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    public function has(string|array $keys): bool
    {
        return array_all((array)$keys, fn($k) => $this->data($k) !== null);
    }

    public function filled(string|array $keys): bool
    {
        foreach ((array)$keys as $k) {
            $v = $this->data($k);
            if ($v === null || $v === '') {
                return false;
            }
        }
        return true;
    }

    public function missing(string|array $keys): bool
    {
        return !$this->has($keys);
    }

    public function string(string $key, string $default = ''): string
    {
        return (string)($this->data($key) ?? $default);
    }

    /* ========== 5.  Content negotiation shortcuts =================== */

    public function prefers(array $mimeTypes): ?string
    {
        // The helper already returns the first matching MIME type (or null)
        return new ContentNegotiator(
            $this->headers(),
        )->preferred($mimeTypes);
    }

    public function expectsJson(): bool
    {
        return parent::expectsJson();
    }

    public function expectsXml(): bool
    {
        return parent::expectsXml();
    }

    public function isJson(): bool
    {
        return (bool)preg_match('#(?:application|text)/(?:[^\s;]+\+)?json#i', $this->getHeaderLine('Content-Type'));
    }

    public function isXml(): bool
    {
        return (bool)preg_match('#(?:application|text)/(?:[^\s;]+\+)?xml#i', $this->getHeaderLine('Content-Type'));
    }

    /* ========== 6.  Files & Headers ================================= */



    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $line = $this->getHeaderLine($name);
        return $line !== '' ? $line : $default;
    }

    /* ========== 7.  Client helpers ================================== */

    public function canonicalQuery(): string
    {
        return Uri::normalizeQueryString($this->getUri()->getQuery());
    }

    public function ip(bool $proxyAware = false): ?string
    {
        $eu = EndUser::from($this);
        return $proxyAware ? $eu->ipViaProxy() : $eu->ipNoProxy();
    }

    public function ua(): array
    {
        return EndUser::from($this)->parseUserAgent();
    }

    /* ========== 8.  Validation stub ================================= */

    public function validate(array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (str_contains((string)$rule, 'required') && !$this->filled($field)) {
                throw new InvalidArgumentException("Field '{$field}' is required");
            }
        }
        return $this->only(array_keys($rules));
    }

    /* quick mutators (clone) */
    public function merge(array $data): self
    {
        return $this->withParsedBody(array_merge($this->post()?->all(), $data));
    }

    public function replace(array $data): self
    {
        return $this->withParsedBody($data);
    }

    /* ========== 9.  ArrayAccess & JsonSerializable ================== */

    public function offsetExists(mixed $offset): bool
    {
        return $this->data((string)$offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data((string)$offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidArgumentException('Request is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidArgumentException('Request is immutable');
    }

    public function jsonSerialize(): array
    {
        return $this->all();
    }

    public function __toString(): string
    {
        return json_encode($this->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /* ========== 10.  Dot-notation accessor =========================== */

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

    /* ========== 11.  Locale helper ================================== */

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
            return $this->cachedLocale = strtolower(substr((string)$language[0], 0, 5));
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

    /* ========== 12.  Convenience aliases ============================ */

    public function wantsJson(): bool
    {
        return $this->expectsJson();
    }

    public function wantsXml(): bool
    {
        return $this->expectsXml();
    }
}
