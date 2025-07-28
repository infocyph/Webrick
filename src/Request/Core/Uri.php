<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use Infocyph\Webrick\Request\Request;
use InvalidArgumentException;

final class Uri
{
    private string $scheme;
    private string $user;
    private string $pass;
    private string $host;
    private ?int $port;
    private string $path;
    private string $query;
    private string $fragment;
    private static array $asciiCache = [];

    public function __construct(string $uri = '')
    {
        /* ---- fast-path: empty URI object ------------------------- */
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

        /* ---- parse once, compute everything in locals ------------ */
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException("Invalid URI: {$uri}");
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $user = $parts['user'] ?? '';
        $pass = $parts['pass'] ?? '';

        $rawHost = $parts['host'] ?? '';
        $host = $rawHost !== '' ? $this->asciiHost($rawHost) : '';

        $portRaw = $parts['port'] ?? null;
        $port = $portRaw === $this->defaultPort($scheme) ? null : $portRaw;

        $path = $this->filterPath($parts['path'] ?? '');
        $query = $this->filterQuery($parts['query'] ?? '');
        $fragment = $this->filterFragment($parts['fragment'] ?? '');

        /* ---- single assignment per property ---------------------- */
        $this->scheme = $scheme;
        $this->user = $user;
        $this->pass = $pass;
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
        $this->query = $query;
        $this->fragment = $fragment;
    }

    /* ------------------------------------------------------------------------- */

    /* ---------- 1. factory: raw string --------------------- */
    public static function from(string $raw): self
    {
        return new self($raw);
    }

    /* ---------- 2. factory: build from $_SERVER ------------ */
    public static function fromServerParams(array $srv): self
    {
        $scheme = self::detectScheme($srv) . '://';
        [$host, $port] = self::detectHostPort($srv);
        $uri = self::detectRequestUri($srv);

        return new self(self::buildFullUrl($scheme, $host, $port, $uri));
    }

    /* ---------- 3. helpers (pure functions, tiny) ---------- */

    /** http vs https deduction (keeps your old rules) */
    private static function detectScheme(array $s): string
    {
        $https = (!empty($s['HTTPS']) && strtolower((string)$s['HTTPS']) === 'on')
            || (strtolower($s['REQUEST_SCHEME'] ?? '') === 'https')
            || (
                (Request::getProxyHeaderFlags() & Request::HEADER_X_FORWARDED_PROTO) !== 0
                && strtolower($s['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            )
            || (strtolower($s['HTTP_FRONT_END_HTTPS'] ?? '') === 'on')
            || ((int)($s['SERVER_PORT'] ?? 0) === 443);

        return $https ? 'https' : 'http';
    }

    /** returns [host, port|null] */
    private static function detectHostPort(array $s): array
    {
        $host = $s['HTTP_HOST'] ?? $s['SERVER_NAME'] ?? 'localhost';

        $port = null;
        if (preg_match('/:(\d+)$/', $host, $m)) {
            $port = (int)$m[1];
            $host = preg_replace('/:\d+$/', '', $host);
        } elseif (!empty($s['SERVER_PORT'])) {
            $port = (int)$s['SERVER_PORT'];
        }
        return [$host, $port];
    }

    private static function detectRequestUri(array $s): string
    {
        return $s['REQUEST_URI'] ?? '/';
    }

    private static function buildFullUrl(string $scheme, string $host, ?int $port, string $reqUri): string
    {
        if ($port !== null && $port === self::getDefaultPortForScheme($scheme)) {
            $port = null;
        }

        return $scheme . $host . ($port ? ":{$port}" : '') . $reqUri;
    }

    private static function getDefaultPortForScheme(string $scheme): ?int
    {
        return strtolower($scheme) === 'https' ? 443 : 80;
    }

    /* ──────────────────────────  String cast  ─────────────────────────────── */

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

    /* ───────────────────────  PSR-7 getters  ─────────────────────────────── */

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getUserInfo(): string
    {
        return $this->user === '' ? '' :
            ($this->pass === '' ? $this->user : "{$this->user}:{$this->pass}");
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $auth = $this->host;
        if ($info = $this->getUserInfo()) {
            $auth = $info . '@' . $auth;
        }
        if ($this->port !== null) {
            $auth .= ':' . $this->port;
        }
        return $auth;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    /* ───────────────────────  PSR-7 immutable setters  ───────────────────── */

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

    public function withHost(string $host): Uri
    {
        $host = $host !== '' ? $this->asciiHost($host) : '';
        if ($host === $this->host) {
            return $this;
        }
        $clone = clone $this;
        $clone->host = $host;
        return $clone;
    }

    public function withPort(?int $port): Uri
    {
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException("Invalid port: {$port}");
        }
        if ($port === $this->port) {
            return $this;
        }
        $clone = clone $this;
        $clone->port = $port === $this->defaultPort($this->scheme) ? null : $port;
        return $clone;
    }

    public function withPath(string $path): Uri
    {
        $path = $this->filterPath($path);
        if ($path === $this->path) {
            return $this;
        }
        $clone = clone $this;
        $clone->path = $path;
        return $clone;
    }

    public function withQuery(string $query): Uri
    {
        $query = $this->filterQuery($query);
        if ($query === $this->query) {
            return $this;
        }
        $clone = clone $this;
        $clone->query = $query;
        return $clone;
    }

    public function withFragment(string $fragment): Uri
    {
        $fragment = $this->filterFragment($fragment);
        if ($fragment === $this->fragment) {
            return $this;
        }
        $clone = clone $this;
        $clone->fragment = $fragment;
        return $clone;
    }

    /* ────────────────────────────  internals  ───────────────────────────── */

    private function asciiHost(string $host): string
    {
        if (isset(self::$asciiCache[$host])) {
            return self::$asciiCache[$host];            // cache-hit
        }

        if ($host[0] === '[') {                         // IPv6 + zone
            return self::$asciiCache[$host] = strtolower($host);
        }

        if (\function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii(
                $host,
                IDNA_NONTRANSITIONAL_TO_ASCII,
                \defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0,
            );
            if ($ascii === false) {
                throw new InvalidArgumentException("Invalid host: {$host}");
            }
            return self::$asciiCache[$host] = strtolower($ascii);
        }

        return self::$asciiCache[$host] = strtolower($host); // intl not loaded
    }

    private function filterPath(string $path): string
    {
        // RFC 3986 §5.2.4 dot-segment removal
        do {
            $old = $path;
            $path = preg_replace('#(/\.?/)#', '/', $path);        // "/./" or "//"
            $path = preg_replace('#/(?!\.\.)[^/]+/\.\./#', '/', $path); // "x/../"
            $path = preg_replace('#^/\.\.(?=/|$)#', '/', $path);  // leading "/../"
        } while ($path !== $old);

        return $path === '' ? '/' : $path;
    }

    private function filterQuery(string $query): string
    {
        return ltrim($query, '?');
    }

    private function filterFragment(string $fragment): string
    {
        return ltrim($fragment, '#');
    }

    private function defaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null
        };
    }
}
