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


    /**
     * Construct a new Uri object from a string (e.g. https://example.com:8080/foo?bar=1#frag).
     *
     * @param string $uri The URI string to parse. If empty, an empty Uri object will be created.
     *
     * @throws InvalidArgumentException If the given URI string is invalid.
     */
    public function __construct(string $uri = '')
    {
        if ($uri !== '') {
            $parts = parse_url($uri);
            if ($parts === false) {
                throw new InvalidArgumentException("Invalid URI: {$uri}");
            }
            $this->scheme   = strtolower($parts['scheme'] ?? '');
            $this->userInfo = $parts['user'] ?? '';
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
            $this->host     = strtolower($parts['host'] ?? '');
            $this->port     = isset($parts['port']) ? (int)$parts['port'] : null;
            $this->path     = $parts['path'] ?? '';
            $this->query    = $parts['query'] ?? '';
            $this->fragment = $parts['fragment'] ?? '';

            // Normalize port if it matches the default
            if ($this->port === $this->getDefaultPort($this->scheme)) {
                $this->port = null;
            }
        }
    }

    /**
     * A helper that tries to build a Uri from server params
     *
     * This does:
     * - scheme detection (http vs. https) from $_SERVER data
     * - host & port detection
     * - path from REQUEST_URI
     * - parse_url to fill fields
     */
    public static function fromServerParams(array $server): self
    {
        $scheme     = self::detectScheme($server);
        [$host, $port] = self::detectHostPort($server);
        $requestUri = self::detectRequestUri($server);
        $fullUrl    = self::buildFullUrl($scheme, $host, $port, $requestUri);

        return new self($fullUrl);
    }


    /**
     * Detects the scheme from the given server params.
     *
     * The method looks at the following keys in the given server params array and returns the scheme based on their values:
     *
     * - `HTTPS`: if set to `'on'` or `'1'`, the scheme is `https://`.
     * - `REQUEST_SCHEME`: if set to `'https'`, the scheme is `https://`.
     * - `HTTP_X_FORWARDED_PROTO`: if set to `'https'`, the scheme is `https://`.
     * - `HTTP_FRONT_END_HTTPS`: if set to `'on'` or `'1'`, the scheme is `https://`.
     * - `SERVER_PORT`: if set to `443`, the scheme is `https://`.
     *
     * Otherwise, the method returns `http://`.
     *
     * @param array $server The server params to detect the scheme from.
     *
     * @return string The detected scheme.
     */
    private static function detectScheme(array $server): string
    {
        $isHttps = false;

        if (
            (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) === 'on')
            || (!empty($server['REQUEST_SCHEME']) && $server['REQUEST_SCHEME'] === 'https')
            || (!empty($server['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $server['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($server['HTTP_FRONT_END_HTTPS']) && strtolower((string) $server['HTTP_FRONT_END_HTTPS']) === 'on')
            || (!empty($server['SERVER_PORT']) && (int)$server['SERVER_PORT'] === 443)
        ) {
            $isHttps = true;
        }

        return $isHttps ? 'https://' : 'http://';
    }


    /**
     * Detects the host and port from the given server params.
     *
     * This method looks for the host and port in the following keys in the given server params array:
     *
     * - `HTTP_HOST`: if present, the host and port are extracted from it.
     * - `SERVER_NAME`: if `HTTP_HOST` is not present, this key is used as the host.
     * - `SERVER_PORT`: if the port is not present in `HTTP_HOST`, this key is used as the port.
     *
     * If neither `HTTP_HOST` nor `SERVER_NAME` are present, the method returns `['localhost', null]`.
     *
     * @param array $server The server params to detect the host from.
     *
     * @return array An array with the host as the first element, and the port as the second element.
     */
    private static function detectHostPort(array $server): array
    {
        // use HTTP_HOST or SERVER_NAME or fallback
        $host = $server['HTTP_HOST']
            ?? $server['SERVER_NAME']
            ?? 'localhost';

        // separate out any :port from the host
        $port = null;
        if (preg_match('/:(\d+)$/', $host, $m)) {
            $port = (int)$m[1];
            $host = preg_replace('/:\d+$/', '', $host);
        } else {
            // if not present in host, try SERVER_PORT
            if (!empty($server['SERVER_PORT'])) {
                $port = (int)$server['SERVER_PORT'];
            }
        }

        return [$host, $port];
    }


    /**
     * Detects the request URI from the given server params.
     *
     * Looks for the `REQUEST_URI` key in the server params array and returns its value.
     * If the key is not present, returns a default of '/'.
     *
     * @param array $server The server params to detect the request URI from.
     *
     * @return string The detected request URI.
     */
    private static function detectRequestUri(array $server): string
    {
        return $server['REQUEST_URI'] ?? '/';
    }


    /**
     * Constructs a full URL from the given components.
     *
     * This method combines the scheme, host, port, and request URI to form a complete URL.
     * If the port is the default for the given scheme, it is omitted from the final URL.
     *
     * @param string $scheme The scheme of the URL (e.g., 'http', 'https').
     * @param string $host The host name or IP address.
     * @param int|null $port The port number, or null if no port is specified.
     * @param string $requestUri The request URI, containing path, query, and fragment.
     *
     * @return string The fully constructed URL.
     */
    private static function buildFullUrl(string $scheme, string $host, ?int $port, string $requestUri): string
    {
        // If the port matches the default, set it to null
        if ($port !== null) {
            $defPort = self::getDefaultPortForScheme(str_starts_with($scheme, 'https') ? 'https' : 'http');
            if ($port === $defPort) {
                $port = null;
            }
        }

        $url = $scheme . $host;
        if ($port !== null) {
            $url .= ':' . $port;
        }
        // add the request URI (which may contain path, query, fragment, etc.)
        $url .= $requestUri;

        return $url;
    }


    /**
     * Returns the default port for the given scheme, or null if no default is available.
     *
     * @param string $scheme The scheme to look up the default port for.
     *
     * @return int|null The default port, or null if no default is available.
     */
    private static function getDefaultPortForScheme(string $scheme): ?int
    {
        return match (strtolower($scheme)) {
            'http' => 80,
            'https' => 443,
            default => null
        };
    }


    /**
     * Converts the URI object to its string representation.
     *
     * The resulting string is constructed following the URI components:
     * - Scheme (e.g., 'http', 'https') followed by '://', if present.
     * - Authority, which includes user info, host, and port, if present.
     * - Path, which is always included.
     * - Query, prefixed by '?', if present.
     * - Fragment, prefixed by '#', if present.
     *
     * @return string The full URI as a string.
     */
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


    /**
     * Retrieves the scheme component of the URI.
     *
     * If the URI has no scheme, an empty string is returned.
     *
     * @return string The scheme component.
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Retrieves the authority component of the URI.
     *
     * The authority component is everything between the double slashes following the scheme and the path.
     * It includes the user info, host, and port.
     *
     * If the host is empty, an empty string is returned.
     *
     * @return string The authority component.
     */
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

    /**
     * Retrieves the user information component of the URI.
     *
     * The user information component typically includes a user name and,
     * optionally, a password, separated by a colon.
     *
     * @return string The user information component.
     */
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    /**
     * Retrieves the host component of the URI.
     *
     * If the host is empty, an empty string is returned.
     *
     * @return string The host component.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Retrieves the port component of the URI.
     *
     * If no port is specified or if the specified port is the default for the
     * URI's scheme, this method returns null.
     *
     * @return int|null The port number, or null if no port is set.
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Retrieves the path component of the URI.
     *
     * The path component is the part of the URI that identifies a specific
     * resource. It is typically the part of the URI after the host and port.
     *
     * @return string The path component.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Retrieves the query string of the URI.
     *
     * The query string is the part of the URI after the path and before the fragment.
     * It is typically used to pass additional parameters to the target of the URI.
     *
     * @return string The query string.
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Retrieves the fragment component of the URI.
     *
     * The fragment component is the part of the URI after the '#' symbol.
     * It is used to identify a specific anchor or section within a target resource.
     *
     * @return string The fragment component.
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * Returns an instance with the specified scheme.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified scheme.
     *
     * @param string $scheme The scheme to use with the new instance.
     *
     * @return static A new instance with the specified scheme.
     */
    public function withScheme(string $scheme): UriInterface
    {
        $new = clone $this;
        $scheme = strtolower($scheme);
        $new->scheme = $scheme;
        if ($new->port === $new->getDefaultPort($scheme)) {
            $new->port = null;
        }

        return $new;
    }

    /**
     * Returns an instance with the specified user information.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified user information.
     *
     * @param string $user     The username to use for authority.
     * @param string|null $password The password associated with $user.
     *
     * @return static A new instance with the specified user information.
     */
    public function withUserInfo(string $user, string $password = null): UriInterface
    {
        $info = $user;
        if ($password) {
            $info .= ':' . $password;
        }
        $new = clone $this;
        $new->userInfo = $info;

        return $new;
    }

    /**
     * Returns an instance with the specified host.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified host.
     *
     * @param string $host The hostname to use with the new instance.
     *
     * @return static A new instance with the specified host.
     */
    public function withHost(string $host): UriInterface
    {
        $new = clone $this;
        $new->host = strtolower($host);

        return $new;
    }

    /**
     * Returns an instance with the specified port.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified port.
     *
     * If the port is invalid, an {@see InvalidArgumentException} will be thrown.
     *
     * @param int|null $port The port to use with the new instance.
     *
     * @return static A new instance with the specified port.
     */
    public function withPort(?int $port): UriInterface
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

    /**
     * Returns an instance with the specified path.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified path.
     *
     * @param string $path The path to use with the new instance.
     *
     * @return static A new instance with the specified path.
     */
    public function withPath(string $path): UriInterface
    {
        $new = clone $this;
        $new->path = $path;
        return $new;
    }

    /**
     * Returns an instance with the specified query string.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified query string.
     *
     * @param string $query The query string to use with the new instance.
     *
     * @return static A new instance with the specified query string.
     */
    public function withQuery(string $query): UriInterface
    {
        $new = clone $this;
        $new->query = ltrim($query, '?');
        return $new;
    }

    /**
     * Returns an instance with the specified fragment.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified fragment.
     *
     * @param string $fragment The fragment to use with the new instance.
     *
     * @return static A new instance with the specified fragment.
     */
    public function withFragment(string $fragment): UriInterface
    {
        $new = clone $this;
        $new->fragment = ltrim($fragment, '#');
        return $new;
    }

    /**
     * Returns the default port for the given scheme, or null if no default is available.
     *
     * @param string $scheme The scheme to look up the default port for.
     *
     * @return int|null The default port, or null if no default is available.
     */
    private function getDefaultPort(string $scheme): ?int
    {
        return match (strtolower($scheme)) {
            'http'  => 80,
            'https' => 443,
            default => null
        };
    }
}
