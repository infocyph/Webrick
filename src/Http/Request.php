<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use ArrayAccess;
use Infocyph\InterMix\Remix\MacroMix;
use JsonSerializable;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A fluent, Laravel-like façade on top of our PSR-7 ServerRequest.
 *
 * Immutable – every mutator returns a cloned instance.
 */
final class Request extends ServerRequest implements ArrayAccess, JsonSerializable, \Stringable
{
    use MacroMix;
    /* -------------------------------------------------------------
     | 1. Shortcuts that already exist in parent
     | ------------------------------------------------------------*/
    public function all(): array
    {
        // JSON overrides form data – mimics Laravel
        return $this->parsedJson()?->all()
            ?: ($this->post()?->all() + $this->query()?->all());
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->__get($key) ?? $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        return filter_var($this->input($key, $default), FILTER_VALIDATE_BOOL);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    public function segment(int $index, $default = null): ?string
    {
        $parts = $this->segments();
        return $parts[$index - 1] ?? $default;
    }

    public function segments(): array
    {
        return array_values(array_filter(
            explode('/', $this->getUri()->getPath()),
            static fn ($s) => $s !== ''
        ));
    }

    public function isMethod(string|array $verbs): bool
    {
        $verbs = (array) $verbs;
        return in_array($this->getEffectiveMethod(), array_map('strtoupper', $verbs), true);
    }

    public function isSecure(): bool
    {
        return $this->getUri()->getScheme() === 'https';
    }

    /**
     * Compare a submitted CSRF token against the one stored in the
     * session/cookie header.  Uses constant-time comparison and supports
     * both masked-token patterns (Laravel style) and plain strings.
     */
    public function matchesCsrfToken(?string $token = null): bool
    {
        // token sent by the client: header, post field or query
        $sent = $token
            ?? $this->header('X-CSRF-TOKEN')
            ?? $this->post('_token')
            ?? $this->query('_token');

        if (!$sent) {
            return false;                     // nothing to compare
        }

        // token kept server-side (session, cookie, …)
        $stored = $_SESSION['_token']          // PSR-15 middleware could inject
            ?? $this->cookie('XSRF-TOKEN')
            ?? null;

        if (!$stored) {
            return false;
        }

        // **masked tokens** (first 40 chars = mask, last 40 = hashed token)
        if (strlen((string) $sent) === 80 && strlen((string) $stored) === 40) {
            $mask   = substr((string) $sent, 0, 40);
            $hashed = substr((string) $sent, 40);

            // XOR-unmask and compare against stored hash
            $unmasked = hash_hmac('sha1', $mask, (string) $stored);
            return hash_equals($unmasked, $hashed);
        }

        // plain token comparison
        return hash_equals($stored, $sent);
    }



    /* -------------------------------------------------------------
     | 2. Data helpers
     | ------------------------------------------------------------*/
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
            if ($this->__get($k) === null) {
                return false;
            }
        }
        return true;
    }

    public function filled(string|array $keys): bool
    {
        foreach ((array)$keys as $k) {
            $v = $this->__get($k);
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
        return (string) ($this->__get($key) ?? $default);
    }

    public function prefers(array $mimeTypes): ?string
    {
        $accept = $this->headers()->accept()->toArray();
        return array_find($mimeTypes, fn ($m) => in_array($m, $accept, true));
    }

    /* -----------------------------------------------------------------
  |  Content-negotiation helpers
  | ----------------------------------------------------------------*/

    /**
     * Did the client ask us to return JSON?
     *
     * - Looks at Accept / X-Requested-With headers (AJAX)
     * - Accepts any “application/json” *or* custom
     *   media-type + “+json” (RFC 6839, e.g. application/hal+json).
     */
    public function expectsJson(): bool
    {
        return $this->prefers(['application/json', 'text/json', '+json']) !== null
            || $this->isAjax();
    }

    /**
     * Is the **current request body** JSON?
     */
    public function isJson(): bool
    {
        return (bool) preg_match(
            '#(?:application|text)/(?:[^\s;]+\+)?json#i',
            $this->getHeaderLine('Content-Type')
        );
    }

    /* ────────────────────────────────────────────────────────────────
     |  XML equivalents (mirrors the JSON helpers)
     | ────────────────────────────────────────────────────────────────*/

    /**
     * Did the client ask us to return XML?
     */
    public function expectsXml(): bool
    {
        return $this->prefers(['application/xml', 'text/xml', '+xml']) !== null
            || $this->isAjax();
    }

    /**
     * Is the **current request body** XML?
     */
    public function isXml(): bool
    {
        return (bool) preg_match(
            '#(?:application|text)/(?:[^\s;]+\+)?xml#i',
            $this->getHeaderLine('Content-Type')
        );
    }


    /* -------------------------------------------------------------
     | 3. File & header access sugar
     | ------------------------------------------------------------*/
    #[\Override]
    public function file(?string $key = null): UploadedFileInterface|array|null
    {
        $files = $this->getUploadedFiles();

        return $key === null
            ? $files                    // full map
            : ($files[$key] ?? null);   // single file or null
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $line = $this->getHeaderLine($name);
        return $line !== '' ? $line : $default;
    }

    /* -------------------------------------------------------------
     | 4. Client helpers (via EndUser & UAParser)
     | ------------------------------------------------------------*/
    public function ip(bool $proxyAware = false): ?string
    {
        $eu = EndUser::from($this);
        return $proxyAware ? $eu->getClientIPProxy() : $eu->getClientIPNoProxy();
    }

    public function ua(): array
    {
        return EndUser::from($this)->parseUserAgent();
    }

    /* -------------------------------------------------------------
     | 5. Simple validator stub (plug real lib later)
     | ------------------------------------------------------------*/
    public function validate(array $rules): array
    {
        // Minimal placeholder; replace with real validation lib later.
        foreach ($rules as $field => $rule) {
            if (str_contains((string) $rule, 'required') && !$this->filled($field)) {
                throw new InvalidArgumentException("Field '{$field}' is required");
            }
        }
        return $this->only(array_keys($rules));
    }

    /* -------------------------------------------------------------
     | 6. PSR-7 immutability helpers (merge, replace, etc.)
     | ------------------------------------------------------------*/
    public function merge(array $data): self
    {
        return $this->withParsedBody(array_merge($this->post()?->all(), $data));
    }

    public function replace(array $data): self
    {
        return $this->withParsedBody($data);
    }

    /* -------------------------------------------------------------
     | 7. ArrayAccess + JsonSerializable
     | ------------------------------------------------------------*/
    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string)$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string)$offset);
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
        return (string) json_encode($this->all(), JSON_UNESCAPED_UNICODE);
    }

    public function data(string $dot, $default = null): mixed
    {
        $segments = explode('.', $dot);
        $value    = $this->__get(array_shift($segments));

        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }
        return $value;
    }

    // -----------------------------------------------------------------
    // Cached locale (NULL = not calculated yet)
    // -----------------------------------------------------------------
    private ?string $cachedLocale = null;

    /**
     * Pick the best-matching locale from the Accept-Language header.
     *
     * @param array|null $supported  e.g. ['en', 'fr', 'de']  – if NULL we just
     *                               return the first language sent by the browser.
     * @param string     $fallback   returned when nothing matches (default 'en').
     */
    public function locale(?array $supported = null, string $fallback = 'en'): string
    {
        /* Fast-path: return the cached value when the caller does not ask
           for a custom $supported list. */
        if ($supported === null && $this->cachedLocale !== null) {
            return $this->cachedLocale;
        }

        /* -----------------------------------------------------------------
           1) Grab the already-parsed Accept-Language header from
              RequestHeaders → it’s already quality-sorted.
              (Request->headers() returns the helper.)
        -----------------------------------------------------------------*/
        $langs = $this->headers()
            ->accept('Accept-Language')
            ->items();           // ['en-US','en;q=0.9','fr;q=0.8', …]

        if ($langs === []) {
            return $fallback;            // client sent nothing
        }

        /* -----------------------------------------------------------------
           2) If no whitelist supplied, take the *first* language string
        -----------------------------------------------------------------*/
        if ($supported === null) {
            return $this->cachedLocale = strtolower(substr((string) $langs[0], 0, 5));
        }

        /* Clean the whitelist to lowercase ISO-codes like 'en', 'fr-CA' */
        $supported = array_map(static fn ($l) => strtolower(str_replace('_', '-', $l)), $supported);

        /* -----------------------------------------------------------------
           3) Iterate through what the browser sent – the list is quality-sorted
              already – pick the first thing that matches the whitelist
        -----------------------------------------------------------------*/
        foreach ($langs as $lang) {
            $lang  = strtolower(str_replace('_', '-', $lang)); // e.g. en-us
            $short = substr($lang, 0, 2);                     // fallback match

            if (in_array($lang, $supported, true)) {
                return $this->cachedLocale = $lang;
            }
            if (in_array($short, $supported, true)) {
                return $this->cachedLocale = $short;
            }
        }

        /* Nothing matched */
        return $fallback;
    }


}
