<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use InvalidArgumentException;

final class Uri implements \Stringable
{
    private const int ASCII_CACHE_LIMIT = 256;

    /** @var array<string,string> */
    private static array $asciiCache = [];

    private string $fragment;

    private string $host;

    private string $pass;

    private string $path;

    private ?int $port;

    private string $query;

    private string $scheme;

    private string $user;

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            $this->scheme = '';
            $this->user = '';
            $this->pass = '';
            $this->host = '';
            $this->port = null;
            $this->path = '/';
            $this->query = '';
            $this->fragment = '';

            return;
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException("Invalid URI: {$uri}");
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $rawHost = $parts['host'] ?? '';
        $port = $parts['port'] ?? null;

        $this->scheme = $scheme;
        $this->user = $parts['user'] ?? '';
        $this->pass = $parts['pass'] ?? '';
        $this->host = $rawHost !== '' ? $this->asciiHost($rawHost) : '';
        $this->port = $port === $this->defaultPort($scheme) ? null : $port;
        $this->path = $this->filterPath($parts['path'] ?? '');
        $this->query = $this->filterQuery($parts['query'] ?? '');
        $this->fragment = $this->filterFragment($parts['fragment'] ?? '');
    }

    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $uri .= $this->path;
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    public static function from(string $raw): self
    {
        return new self($raw);
    }

    /** Construct directly from already separated transport components. */
    public static function fromComponents(
        string $scheme = '',
        string $host = '',
        ?int $port = null,
        string $path = '/',
        string $query = '',
        string $fragment = '',
        string $user = '',
        string $pass = '',
    ): self {
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException("Invalid port: {$port}");
        }

        $uri = new self();
        $uri->scheme = strtolower($scheme);
        $uri->user = $user;
        $uri->pass = $pass;
        $uri->host = $host !== '' ? $uri->asciiHost($host) : '';
        $uri->port = $port === $uri->defaultPort($uri->scheme) ? null : $port;
        $uri->path = $uri->filterPath($path);
        $uri->query = $uri->filterQuery($query);
        $uri->fragment = $uri->filterFragment($fragment);

        return $uri;
    }

    /** @param array<string,mixed> $srv */
    public static function fromServerParams(array $srv, ?int $trustedProxyFlags = null): self
    {
        $scheme = UriServerParams::detectScheme($srv, $trustedProxyFlags);
        [$host, $port] = UriServerParams::detectHostPort($srv, $trustedProxyFlags);
        [$path, $query, $fragment] = self::splitRequestTarget(UriServerParams::detectRequestUri($srv));

        return self::fromComponents($scheme, $host, $port, $path, $query, $fragment);
    }

    public static function normalizeQueryString(string $qs): string
    {
        if ($qs === '') {
            return '';
        }

        $pairs = preg_split('/[&;]+/', $qs, -1, PREG_SPLIT_NO_EMPTY);
        if ($pairs === false) {
            return '';
        }

        /** @var array<string,list<string>> $bucket */
        $bucket = [];
        foreach ($pairs as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $bucket[rawurldecode($key)][] = rawurldecode($value);
        }

        ksort($bucket, SORT_STRING);

        $out = '';
        foreach ($bucket as $key => $values) {
            $encodedKey = rawurlencode($key);
            foreach ($values as $value) {
                $encodedValue = rawurlencode($value);
                $out .= $encodedKey . ($encodedValue === '' ? '' : '=' . $encodedValue) . '&';
            }
        }

        return rtrim($out, '&');
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;
        $info = $this->getUserInfo();
        if ($info !== '') {
            $authority = $info . '@' . $authority;
        }
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getUserInfo(): string
    {
        return $this->user === '' ? ''
            : ($this->pass === '' ? $this->user : "{$this->user}:{$this->pass}");
    }

    public function withFragment(string $fragment): Uri
    {
        $fragment = $this->filterFragment($fragment);
        if ($fragment === $this->fragment) {
            return $this;
        }

        return $this->withComponent('fragment', $fragment);
    }

    public function withHost(string $host): Uri
    {
        $host = $host !== '' ? $this->asciiHost($host) : '';
        if ($host === $this->host) {
            return $this;
        }

        return $this->withComponent('host', $host);
    }

    public function withPath(string $path): Uri
    {
        $path = $this->filterPath($path);
        if ($path === $this->path) {
            return $this;
        }

        return $this->withComponent('path', $path);
    }

    public function withPort(?int $port): Uri
    {
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException("Invalid port: {$port}");
        }
        if ($port === $this->port) {
            return $this;
        }

        return $this->withComponent('port', $port === $this->defaultPort($this->scheme) ? null : $port);
    }

    public function withQuery(string $query): Uri
    {
        $query = $this->filterQuery($query);
        if ($query === $this->query) {
            return $this;
        }

        return $this->withComponent('query', $query);
    }

    public function withScheme(string $scheme): Uri
    {
        $scheme = strtolower($scheme);
        if ($scheme === $this->scheme) {
            return $this;
        }

        $clone = clone $this;
        $clone->scheme = $scheme;
        if ($clone->port === $clone->defaultPort($scheme)) {
            $clone->port = null;
        }

        return $clone;
    }

    public function withUserInfo(string $user, ?string $password = null): Uri
    {
        if ($user === $this->user && ($password ?? '') === $this->pass) {
            return $this;
        }

        $clone = clone $this;
        $clone->user = $user;
        $clone->pass = $password ?? '';

        return $clone;
    }

    private static function splitRequestTarget(string $target): array
    {
        $fragment = '';
        $hash = strpos($target, '#');
        if ($hash !== false) {
            $fragment = substr($target, $hash + 1);
            $target = substr($target, 0, $hash);
        }

        $query = '';
        $question = strpos($target, '?');
        if ($question !== false) {
            $query = substr($target, $question + 1);
            $target = substr($target, 0, $question);
        }

        return [$target === '' ? '/' : $target, $query, $fragment];
    }

    private function asciiHost(string $host): string
    {
        if (preg_match('/[\x00-\x20\x7f\/\\\\@?#]/', $host) === 1) {
            throw new InvalidArgumentException("Invalid host: {$host}");
        }
        if (isset(self::$asciiCache[$host])) {
            return self::$asciiCache[$host];
        }

        if ($host[0] === '[') {
            $normalized = strtolower($host);
        } elseif (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii(
                $host,
                IDNA_NONTRANSITIONAL_TO_ASCII,
                defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0,
            );
            if ($ascii === false) {
                throw new InvalidArgumentException("Invalid host: {$host}");
            }
            $normalized = strtolower($ascii);
        } else {
            $normalized = strtolower($host);
        }

        if (count(self::$asciiCache) < self::ASCII_CACHE_LIMIT) {
            self::$asciiCache[$host] = $normalized;
        }

        return $normalized;
    }

    private function defaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    private function filterFragment(string $fragment): string
    {
        return ltrim($fragment, '#');
    }

    private function filterPath(string $path): string
    {
        do {
            $old = $path;
            $path = preg_replace('#(/\.?/)#', '/', $path) ?? $old;
            $path = preg_replace('#/(?!\.\.)[^/]+/\.\./#', '/', $path) ?? $old;
            $path = preg_replace('#^/\.\.(?=/|$)#', '/', $path) ?? $old;
        } while ($path !== $old);

        return $path === '' ? '/' : $path;
    }

    private function filterQuery(string $query): string
    {
        return ltrim($query, '?');
    }

    private function withComponent(string $property, mixed $value): self
    {
        $clone = clone $this;
        $clone->{$property} = $value;

        return $clone;
    }
}
