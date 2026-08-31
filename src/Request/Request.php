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
use Infocyph\Webrick\Request\Support\CidrNetwork;
use Infocyph\Webrick\Request\Support\IpCidr;
use Infocyph\Webrick\Support\HttpUtils;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/** @implements ArrayAccess<string,mixed> */
class Request extends NativeServerRequest implements ArrayAccess, JsonSerializable, Stringable
{
    use MacroMix;

    public const HEADER_FORWARDED = 0b10000;

    public const HEADER_X_FORWARDED_FOR = 0b00001;

    public const HEADER_X_FORWARDED_HOST = 0b00010;

    public const HEADER_X_FORWARDED_PORT = 0b01000;

    public const HEADER_X_FORWARDED_PROTO = 0b00100;

    private static int $trustedHeaderFlags = 0;

    /** @var list<string> */
    private static array $trustedProxyCidrs = [];

    private static bool $trustedProxyConfigurationFrozen = false;

    /** @var list<CidrNetwork> */
    private static array $trustedProxyNetworks = [];

    /** @var array<string,mixed>|null */
    private ?array $cachedAll = null;

    private ?string $cachedLocale = null;

    /** @var list<string>|null */
    private ?array $cachedSegments = null;

    protected function __clone(): void
    {
        parent::__clone();
        $this->resetDerivedCaches();
    }

    public function __toString(): string
    {
        return json_encode($this->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string|list<string>> $headers
     */
    public static function fake(
        array $query = [],
        array $post = [],
        array $headers = [],
        string $method = HttpMethodEnum::GET->value,
        string $uri = '/',
    ): self {
        return new self(
            HttpMethodEnum::normalize($method),
            Uri::from($uri),
            [],
            $headers,
            parsed: $post,
            files: [],
            query: $query,
            cookies: [],
        );
    }

    public static function freezeTrustedProxyConfiguration(): void
    {
        self::$trustedProxyConfigurationFrozen = true;
    }

    public static function fromGlobals(): self
    {
        $native = parent::createFromGlobals();

        return new self(
            $native->getMethod(),
            $native->getUri(),
            $native->getServerParams(),
            $native->getHeaders(),
            $native->getBody(),
            $native->getProtocolVersion(),
            $native->getParsedBody(),
            $native->getUploadedFiles(),
            query: $native->getQueryParams(),
            cookies: $native->getCookieParams(),
        );
    }

    public static function getProxyHeaderFlags(): int
    {
        return self::$trustedHeaderFlags;
    }

    /** @return list<string> */
    public static function getTrustedProxyCidrs(): array
    {
        return self::$trustedProxyCidrs;
    }

    /** @return list<CidrNetwork> */
    public static function getTrustedProxyNetworks(): array
    {
        return self::$trustedProxyNetworks;
    }

    /** @param array<string,mixed> $server */
    public static function isFromTrustedProxy(array $server): bool
    {
        $peer = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($peer) || $peer === '') {
            return false;
        }

        return array_any(
            self::$trustedProxyNetworks,
            static fn(CidrNetwork $network): bool => $network->matches($peer),
        );
    }

    /** @param list<string> $cidrs */
    public static function setTrustedProxies(array $cidrs, ?int $headerFlags = null): void
    {
        if (self::$trustedProxyConfigurationFrozen) {
            throw new \LogicException('Trusted-proxy configuration is frozen for production runtime.');
        }

        $networks = [];
        $normalized = [];
        foreach ($cidrs as $cidr) {
            $cidr = trim($cidr);
            if ($cidr === '') {
                throw new InvalidArgumentException('Trusted proxy CIDR must not be empty.');
            }
            $networks[] = IpCidr::compile($cidr);
            $normalized[] = $cidr;
        }
        self::$trustedProxyCidrs = $normalized;
        self::$trustedProxyNetworks = $networks;
        if ($headerFlags !== null) {
            self::$trustedHeaderFlags = $headerFlags;
        }
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cachedAll !== null) {
            return $this->cachedAll;
        }
        $parsed = $this->getParsedBody();
        $body = is_array($parsed) ? $parsed : [];

        return $this->cachedAll = self::stringMap($body + $this->getQueryParams());
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = filter_var($this->data($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    public function canonicalQuery(): string
    {
        return Uri::normalizeQueryString($this->getUri()->getQuery());
    }

    public function data(string $dot, mixed $default = null): mixed
    {
        $segments = explode('.', $dot);
        $key = array_shift($segments);
        $input = $this->all();
        if ($key === null || !array_key_exists($key, $input)) {
            return $default;
        }

        $value = $input[$key];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value ?? $default;
    }

    /**
     * @param list<string> $supported
     * @param list<string> $sources
     * @return array{0:string,1:string}
     */
    public function detectLocale(
        array $supported,
        string $fallback = 'en',
        array $sources = ['attr', 'route', 'query', 'cookie', 'header', 'default'],
    ): array {
        $supported = self::normalizeLocaleList($supported);
        $fallback = self::normalizeLocale($fallback);

        foreach ($sources as $source) {
            $hit = match ($source) {
                'attr' => $this->resolveLocaleFromAttr($supported),
                'route' => $this->resolveLocaleFromRoute($supported),
                'query' => $this->resolveLocaleFromQuery($supported),
                'cookie' => $this->resolveLocaleFromCookie($supported),
                'header' => $this->resolveLocaleFromHeader($supported, $fallback),
                'default' => $fallback,
                default => null,
            };
            if ($hit !== null) {
                return [$hit, $source];
            }
        }

        return [$fallback, 'default'];
    }

    /** @param string|list<string> $keys @return array<string,mixed> */
    public function except(string|array $keys): array
    {
        return self::stringMap(array_diff_key($this->all(), array_flip(self::stringList($keys))));
    }

    #[\Override]
    public function expectsJson(): bool
    {
        return parent::expectsJson();
    }

    #[\Override]
    public function expectsXml(): bool
    {
        return parent::expectsXml();
    }

    /** @param string|list<string> $keys */
    public function filled(string|array $keys): bool
    {
        foreach (self::stringList($keys) as $key) {
            $value = $this->data($key);
            if ($value === null || $value === '') {
                return false;
            }
        }

        return true;
    }

    /** @param string|list<string> $keys */
    public function has(string|array $keys): bool
    {
        return array_all(self::stringList($keys), fn($key) => !($this->data($key) === null));
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

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data($key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = filter_var($this->data($key), FILTER_VALIDATE_INT);

        return $value !== false ? $value : $default;
    }

    public function ip(bool $proxyAware = false): ?string
    {
        $user = EndUser::from($this, self::$trustedProxyNetworks);

        return $proxyAware ? $user->ipViaProxy() : $user->ipNoProxy();
    }

    public function isJson(): bool
    {
        return HttpUtils::isJsonContentType($this->getHeaderLine('Content-Type'));
    }

    /** @param string|list<string> $verbs */
    public function isMethod(string|array $verbs): bool
    {
        $normalized = [];
        foreach (self::stringList($verbs) as $verb) {
            $normalized[] = HttpMethodEnum::normalize($verb);
        }

        return in_array(HttpMethodEnum::normalize($this->getEffectiveMethod()), $normalized, true);
    }

    public function isSecure(): bool
    {
        return $this->getUri()->getScheme() === 'https';
    }

    public function isXml(): bool
    {
        return HttpUtils::isXmlContentType($this->getHeaderLine('Content-Type'));
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->all();
    }

    /** @param list<string>|null $supported */
    public function locale(?array $supported = null, string $fallback = 'en', bool $cache = true): string
    {
        $fallback = self::normalizeLocale($fallback);
        if ($cache && $supported === null && $this->cachedLocale !== null) {
            return $this->cachedLocale;
        }

        $languages = $this->headers()->accept('Accept-Language');
        if ($languages === []) {
            return $fallback;
        }
        if ($supported === null) {
            $locale = self::normalizeLocale($languages[0]);
            if ($cache) {
                $this->cachedLocale = $locale;
            }

            return $locale;
        }

        $supported = self::normalizeLocaleList($supported);
        foreach ($languages as $language) {
            $language = self::normalizeLocale($language);
            $primary = self::primaryLocale($language);
            if (in_array($language, $supported, true)) {
                return $language;
            }
            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return $fallback;
    }

    public function matchesCsrfToken(Csrf $csrf, ?string $token = null): bool
    {
        return $token !== null ? $csrf->matchesValue($token) : $csrf->matches($this);
    }

    /** @param array<string,mixed> $data */
    public function merge(array $data): self
    {
        $parsed = $this->getParsedBody();
        $body = is_array($parsed) ? $parsed : [];

        return $this->withParsedBody(self::stringMap(array_merge($body, $data)));
    }

    /** @param string|list<string> $keys */
    public function missing(string|array $keys): bool
    {
        return !$this->has($keys);
    }

    public function offsetExists(mixed $offset): bool
    {
        $key = self::toStringKey($offset);

        return $key !== null && $this->data($key) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        $key = self::toStringKey($offset);

        return $key === null ? null : $this->data($key);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidArgumentException('Request is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidArgumentException('Request is immutable');
    }

    /** @param string|list<string> $keys @return array<string,mixed> */
    public function only(string|array $keys): array
    {
        return array_intersect_key($this->all(), array_flip(self::stringList($keys)));
    }

    /** @param list<string> $mimeTypes */
    public function prefers(array $mimeTypes): ?string
    {
        return new ContentNegotiator($this->headers())->preferred($mimeTypes);
    }

    /** @param array<string,mixed> $data */
    public function replace(array $data): self
    {
        return $this->withParsedBody($data);
    }

    /** @param string|list<string> $patterns */
    public function routeIs(string|array $patterns): bool
    {
        $target = $this->getRequestTarget();

        return array_any(self::stringList($patterns), fn($pattern) => preg_match('#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#', $target) === 1);
    }

    public function segment(int $index, mixed $default = null): mixed
    {
        return $this->segments()[$index - 1] ?? $default;
    }

    /** @return list<string> */
    public function segments(): array
    {
        return $this->cachedSegments ??= array_values(array_filter(
            explode('/', $this->getUri()->getPath()),
            static fn(string $segment): bool => $segment !== '',
        ));
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->data($key);

        return is_string($value) ? $value : $default;
    }

    /** @return array<string,string> */
    public function ua(): array
    {
        return EndUser::from($this, self::$trustedProxyNetworks)->parseUserAgent();
    }

    /** @param array<string,string> $rules @return array<string,mixed> */
    public function validate(array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (str_contains($rule, 'required') && !$this->filled($field)) {
                throw new InvalidArgumentException("Field '{$field}' is required");
            }
        }

        return $this->only(array_keys($rules));
    }

    public function wantsJson(): bool
    {
        return $this->expectsJson();
    }

    public function wantsXml(): bool
    {
        return $this->expectsXml();
    }

    /** @param array<string,mixed> $cookies */
    #[\Override]
    public function withCookieParams(array $cookies): static
    {
        return parent::withCookieParams($cookies);
    }

    /** @param array<string,mixed>|object|null $data */
    #[\Override]
    public function withParsedBody(object|array|null $data): static
    {
        return parent::withParsedBody($data);
    }

    /** @param array<string,mixed> $query */
    #[\Override]
    public function withQueryParams(array $query): static
    {
        return parent::withQueryParams($query);
    }

    #[\Override]
    public function withUri(Uri $uri, bool $preserveHost = false): static
    {
        return parent::withUri($uri, $preserveHost);
    }

    private static function normalizeLocale(string $locale): string
    {
        return strtolower(str_replace('_', '-', trim($locale)));
    }

    /** @param list<string> $locales @return list<string> */
    private static function normalizeLocaleList(array $locales): array
    {
        $normalized = [];
        foreach ($locales as $locale) {
            $normalized[] = self::normalizeLocale($locale);
        }

        return array_values(array_unique($normalized));
    }

    private static function primaryLocale(string $locale): string
    {
        $separator = strpos($locale, '-');

        return $separator === false ? $locale : substr($locale, 0, $separator);
    }

    /** @param string|list<string> $value @return list<string> */
    private static function stringList(string|array $value): array
    {
        return is_string($value) ? [$value] : $value;
    }

    /** @param array<array-key,mixed> $value @return array<string,mixed> */
    private static function stringMap(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    private static function toStringKey(mixed $key): ?string
    {
        return match (true) {
            is_string($key) => $key,
            is_int($key), is_float($key), is_bool($key) => (string) $key,
            default => null,
        };
    }

    /** @param list<string> $supported */
    private function pickLocale(?string $raw, array $supported): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $candidate = self::normalizeLocale($raw);
        if (in_array($candidate, $supported, true)) {
            return $candidate;
        }
        $primary = self::primaryLocale($candidate);

        return in_array($primary, $supported, true) ? $primary : null;
    }

    private function resetDerivedCaches(): void
    {
        $this->cachedAll = null;
        $this->cachedLocale = null;
        $this->cachedSegments = null;
    }

    /** @param list<string> $supported */
    private function resolveLocaleFromAttr(array $supported): ?string
    {
        $value = $this->getAttribute('locale') ?? $this->getAttribute('lang');

        return is_string($value) ? $this->pickLocale($value, $supported) : null;
    }

    /** @param list<string> $supported */
    private function resolveLocaleFromCookie(array $supported): ?string
    {
        return $this->pickLocale(
            $this->resolveLocaleScalar($this->cookie('locale'), $this->cookie('lang')),
            $supported,
        );
    }

    /** @param list<string> $supported */
    private function resolveLocaleFromHeader(array $supported, string $fallback): ?string
    {
        return $this->pickLocale($this->locale($supported, $fallback, true), $supported);
    }

    /** @param list<string> $supported */
    private function resolveLocaleFromQuery(array $supported): ?string
    {
        return $this->pickLocale(
            $this->resolveLocaleScalar($this->query('locale'), $this->query('lang')),
            $supported,
        );
    }

    /** @param list<string> $supported */
    private function resolveLocaleFromRoute(array $supported): ?string
    {
        $params = $this->getAttribute('route_params');
        if (!is_array($params)) {
            return null;
        }
        $value = $params['locale'] ?? $params['lang'] ?? null;

        return is_string($value) ? $this->pickLocale($value, $supported) : null;
    }

    private function resolveLocaleScalar(mixed $primary, mixed $fallback): string
    {
        return is_string($primary) ? $primary : (is_string($fallback) ? $fallback : '');
    }
}
