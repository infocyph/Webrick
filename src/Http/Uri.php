<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

class Uri implements UriInterface
{
    private string $scheme = '';

    private string $userInfo = '';

    private string $host = '';

    private ?int $port = null;

    private string $path = '';

    private string $query = '';

    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri !== '') {
            $parts = parse_url($uri);
            if ($parts === false) {
                throw new InvalidArgumentException("Invalid URI: {$uri}");
            }
            $this->scheme = strtolower($parts['scheme'] ?? '');
            $this->userInfo = $parts['user'] ?? '';
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
            $this->host = strtolower($parts['host'] ?? '');
            $this->port = isset($parts['port']) ? (int)$parts['port'] : null;
            $this->path = $parts['path'] ?? '';
            $this->query = $parts['query'] ?? '';
            $this->fragment = $parts['fragment'] ?? '';
            // Normalize port if default
            if ($this->port === $this->getDefaultPort($this->scheme)) {
                $this->port = null;
            }
        }
    }

    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }
        $authority = $this->getAuthority();
        if ($authority) {
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

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
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

    public function withScheme($scheme): UriInterface
    {
        $new = clone $this;
        $scheme = strtolower($scheme);
        $new->scheme = $scheme;
        if ($new->port === $new->getDefaultPort($scheme)) {
            $new->port = null;
        }

        return $new;
    }

    public function withUserInfo($user, $password = null): UriInterface
    {
        $info = $user;
        if ($password) {
            $info .= ':' . $password;
        }
        $new = clone $this;
        $new->userInfo = $info;

        return $new;
    }

    public function withHost($host): UriInterface
    {
        $new = clone $this;
        $new->host = strtolower($host);

        return $new;
    }

    public function withPort($port): UriInterface
    {
        $new = clone $this;
        if ($port !== null) {
            $port = (int)$port;
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException("Invalid port: {$port}");
            }
            if ($port === $new->getDefaultPort($new->scheme)) {
                $port = null;
            }
        }
        $new->port = $port;

        return $new;
    }

    public function withPath($path): UriInterface
    {
        $new = clone $this;
        $new->path = $path;

        return $new;
    }

    public function withQuery($query): UriInterface
    {
        $new = clone $this;
        $new->query = ltrim($query, '?');

        return $new;
    }

    public function withFragment($fragment): UriInterface
    {
        $new = clone $this;
        $new->fragment = ltrim($fragment, '#');

        return $new;
    }

    private function getDefaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null
        };
    }
}
