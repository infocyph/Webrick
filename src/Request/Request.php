<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request;

use ArrayAccess;
use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\Webrick\Http\EndUser;
use Infocyph\Webrick\Http\Uri;
use InvalidArgumentException;
use JsonSerializable;
use Psr\Http\Message\UploadedFileInterface;
use Stringable;

/**
 * Fluent, Laravel-inspired façade around our PSR-7 `ServerRequest`.
 *
 * Immutable – every mutator returns a cloned instance.
 */
final class Request extends ServerRequest implements ArrayAccess, JsonSerializable, Stringable
{
    use MacroMix;

    /* ────────────────────────── caches ────────────────────────── */
    private ?array  $cachedAll      = null;
    private ?array  $cachedSegments = null;
    private ?string $cachedLocale   = null;

    /* ============================================================
     | 0.  Factory helpers (for tests, etc.)
     ============================================================*/
    public static function fake(
        array  $query   = [],
        array  $post    = [],
        array  $headers = [],
        string $method  = 'GET',
        string $uri     = '/'
    ): self {
        return (new self(
            $method,
            Uri::from($uri),
            $_SERVER,
            $headers,
        ))
            ->withQueryParams($query)
            ->withParsedBody($post);
    }

    public static function setTrustedProxies(array $cidrs): void
    {
        EndUser::setTrustedProxies($cidrs);
    }

    /* ============================================================
     | 1.  Basic accessors
     ============================================================*/
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
        $val = filter_var(
            $this->data($key),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        );

        return $val ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = filter_var($this->data($key), FILTER_VALIDATE_INT);
        return $v !== false ? $v : $default;
    }

    /* ============================================================
     | 2.  URI helpers
     ============================================================*/
    public function segments(): array
    {
        if ($this->cachedSegments === null) {
            $this->cachedSegments = array_values(array_filter(
                explode('/', $this->getUri()->getPath()),
                static fn(string $s) => $s !== ''
            ));
        }

        return $this->cachedSegments;
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
        $verbs = array_map('strtoupper', (array)$verbs);
        return in_array($this->getEffectiveMethod(), $verbs, true);
    }

    public function isSecure(): bool
    {
        return $this->getUri()->getScheme() === 'https';
    }

    /* ============================================================
     | 3.  CSRF helpers
     ============================================================*/
    public function matchesCsrfToken(?string $token = null): bool
    {
        $sent = $token
            ?? $this->header('X-CSRF-TOKEN')
            ?? $this->post('_token')
            ?? $this->query('_token');

        $stored = $_SESSION['_token']          // e.g. added by middleware
            ?? $this->cookie('XSRF-TOKEN');

        if (!$sent || !$stored) {
            return false;
        }

        // Masked Laravel-style token
        if (strlen($sent) === 80 && strlen($stored) === 40) {
            $mask   = substr($sent, 0, 40);
            $hashed = substr($sent, 40);       // SHA-1(state + mask)
            return hash_equals($hashed, sha1($mask . $stored));
        }

        return hash_equals($stored, $sent);
    }

    /* ============================================================
     | 4.  Data helpers
     ============================================================*/
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
        foreach ((array)$keys as $k) {
            if ($this->data($k) === null) {
                return false;
            }
        }

        return true;
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

    /* ============================================================
     | 5.  Content negotiation
     ============================================================*/
    public function prefers(array $mimeTypes): ?string
    {
        // Already quality-sorted
        $accept = $this->headers()->accept()->toArray();

        foreach ($mimeTypes as $m) {
            if (str_starts_with($m, '+')) {        // “+json” wildcard
                foreach ($accept as $a) {
                    if (str_ends_with($a, $m)) {
                        return $a;
                    }
                }
            } elseif (in_array($m, $accept, true)) {
                return $m;
            }
        }

        return null;
    }

    /* ----- JSON / XML helpers ----- */
    public function expectsJson(): bool
    {
        return parent::expectsJson()
            || $this->prefers(['application/json', 'text/json', '+json']) !== null;
    }

    public function isJson(): bool
    {
        return (bool)preg_match('#(?:application|text)/(?:[^\s;]+\+)?json#i', $this->getHeaderLine('Content-Type'));
    }

    public function expectsXml(): bool
    {
        return parent::expectsXml()
            || $this->prefers(['application/xml', 'text/xml', '+xml']) !== null;
    }

    public function isXml(): bool
    {
        return (bool)preg_match('#(?:application|text)/(?:[^\s;]+\+)?xml#i', $this->getHeaderLine('Content-Type'));
    }

    /* ============================================================
     | 6.  Files & Headers
     ============================================================*/
    #[\Override]
    public function file(?string $key = null): UploadedFileInterface|array|null
    {
        $files = $this->getUploadedFiles();
        return $key === null ? $files : ($files[$key] ?? null);
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $line = $this->getHeaderLine($name);
        return $line !== '' ? $line : $default;
    }

    /* ============================================================
     | 7.  Client helpers
     ============================================================*/
    public function ip(bool $proxyAware = false): ?string
    {
        $eu = EndUser::from($this);
        return $proxyAware ? $eu->ipViaProxy() : $eu->ipNoProxy();
    }

    public function ua(): array
    {
        return EndUser::from($this)->parseUserAgent();
    }

    /* ============================================================
     | 8.  Validation stub
     ============================================================*/
    public function validate(array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (str_contains((string)$rule, 'required') && !$this->filled($field)) {
                throw new InvalidArgumentException("Field '{$field}' is required");
            }
        }

        return $this->only(array_keys($rules));
    }

    public function merge(array $data): self
    {
        return $this->withParsedBody(
            array_merge($this->post()?->all(), $data)
        );
    }

    public function replace(array $data): self
    {
        return $this->withParsedBody($data);
    }

    /* ============================================================
     | 9.  ArrayAccess & JsonSerializable
     ============================================================*/
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

    public function jsonSerialize(): mixed
    {
        return $this->all();
    }

    public function __toString(): string
    {
        return json_encode($this->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /* ============================================================
     | 10.  Dot-notation accessor
     ============================================================*/
    public function data(string $dot, mixed $default = null): mixed
    {
        $segments = explode('.', $dot);
        $value    = parent::__get(array_shift($segments));

        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }

        return $value ?? $default;
    }

    /* ============================================================
     | 11.  Locale helper
     ============================================================*/
    public function locale(
        ?array $supported = null,
        string $fallback  = 'en',
        bool   $cache     = true
    ): string {
        if ($cache && $supported === null && $this->cachedLocale !== null) {
            return $this->cachedLocale;
        }

        $langs = $this->headers()->accept('Accept-Language')->toArray();
        if ($langs === []) {
            return $fallback;
        }

        if ($supported === null) {
            return $this->cachedLocale = strtolower(substr((string)$langs[0], 0, 5));
        }

        $supported = array_map(
            static fn(string $l) => strtolower(str_replace('_', '-', $l)),
            $supported
        );

        foreach ($langs as $lang) {
            $lang  = strtolower(str_replace('_', '-', $lang));
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

    /* ============================================================
     | 12.  Convenience aliases
     ============================================================*/
    public function wantsJson(): bool
    {
        return $this->expectsJson();
    }

    public function wantsXml(): bool
    {
        return $this->expectsXml();
    }
}
