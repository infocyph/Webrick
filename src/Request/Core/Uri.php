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

    /**
     * Create a new Uri object from a given string or empty object.
     *
     * If the string is empty, it will create an empty Uri object.
     * Otherwise, it will parse the string and compute all the properties
     * needed for the Uri object.
     *
     * @param string $uri The string to parse into a Uri object.
     * @throws InvalidArgumentException If the given string is not a valid URI.
     */
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

    /**
     * Constructs a Uri object from a raw URI string.
     *
     * @param string $raw The raw URI string.
     * @return self A new Uri object.
     */
    public static function from(string $raw): self
    {
        return new self($raw);
    }

    /**
     * Constructs a Uri object from server parameters ($_SERVER).
     *
     * This method takes into account proxy headers (e.g. Forwarded, X-Forwarded-*)
     * if configured, and returns a Uri object that reflects the best available
     * information about the client.
     *
     * @param array $srv The server parameters, typically from $_SERVER.
     * @return self A new Uri object.
     */
    public static function fromServerParams(array $srv): self
    {
        $scheme = self::detectScheme($srv);          // 'http' | 'https'
        [$host, $port] = self::detectHostPort($srv); // honors proxy flags
        $uri = self::detectRequestUri($srv);

        return new self(self::buildFullUrl($scheme, $host, $port, $uri));
    }

    /**
     * Detects the scheme (http or https) from server parameters, considering proxy headers if configured.
     *
     * The detection follows this order of precedence:
     * 1. RFC 7239 Forwarded header (proto parameter)
     * 2. X-Forwarded-Proto header
     * 3. Direct socket view (HTTPS, REQUEST_SCHEME, HTTP_FRONT_END_HTTPS, SERVER_PORT)
     *
     * @param array $s The server parameters, typically from $_SERVER.
     * @return string The detected scheme, either 'http' or 'https'.
     */
    private static function detectScheme(array $s): string
    {
        if ($p = self::protoFromForwarded($s)) {
            return $p;
        }
        if ($p = self::protoFromXForwarded($s)) {
            return $p;
        }
        return self::protoFromServer($s);
    }

    /**
     * Detects the protocol (http or https) from the Forwarded header.
     *
     * If the Forwarded header is present and has a "proto" parameter,
     * returns the protocol. Otherwise, returns null.
     *
     * @param array $s The server parameters, typically from $_SERVER.
     * @return string|null The protocol (http or https) or null if not present.
     */
    private static function protoFromForwarded(array $s): ?string
    {
        if ((Request::getProxyHeaderFlags() & Request::HEADER_FORWARDED) === 0) {
            return null;
        }
        $line = (string)($s['HTTP_FORWARDED'] ?? '');
        if ($line === '') {
            return null;
        }
        $first = explode(',', $line)[0];
        if (preg_match('/proto="?([a-z]+)"?/i', $first, $m)) {
            $p = strtolower($m[1]);
            return ($p === 'https' || $p === 'http') ? $p : null;
        }
        return null;
    }

    /**
     * Detects the protocol from the X-Forwarded-Proto header.
     * Returns one of 'http', 'https' or null.
     *
     * @param array $s The server parameters, typically from $_SERVER.
     * @return string|null The detected protocol, or null if not present.
     */
    private static function protoFromXForwarded(array $s): ?string
    {
        if ((Request::getProxyHeaderFlags() & Request::HEADER_X_FORWARDED_PROTO) === 0) {
            return null;
        }
        $first = strtolower(trim(explode(',', (string)($s['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $first === 'https' ? 'https' : ($first === 'http' ? 'http' : null);
    }

    /**
     * Detects the protocol from the server parameters.
     * The detection follows this order of precedence:
     * 1. $_SERVER['HTTPS'] (on/off)
     * 2. $_SERVER['REQUEST_SCHEME'] (https/http)
     * 3. $_SERVER['HTTP_FRONT_END_HTTPS'] (on/off)
     * 4. $_SERVER['SERVER_PORT'] (443 implies https)
     *
     * @param array $s The server parameters, typically from $_SERVER.
     * @return string The detected protocol, either 'http' or 'https'.
     */
    private static function protoFromServer(array $s): string
    {
        $https =
            (!empty($s['HTTPS']) && strtolower((string)$s['HTTPS']) === 'on')
            || (strtolower($s['REQUEST_SCHEME'] ?? '') === 'https')
            || (strtolower($s['HTTP_FRONT_END_HTTPS'] ?? '') === 'on')
            || ((int)($s['SERVER_PORT'] ?? 0) === 443);

        return $https ? 'https' : 'http';
    }

    /**
     * Detects the host and port from server parameters, considering proxy headers if configured.
     *
     * The detection follows this order of precedence:
     * 1. RFC 7239 Forwarded header (host parameter)
     * 2. X-Forwarded-Host header
     * 3. Direct socket view (HTTP_HOST or SERVER_NAME and SERVER_PORT)
     *
     * If the port is not specified in the host headers, it may be supplemented
     * by the X-Forwarded-Port header if allowed by proxy header flags.
     *
     * @param array $s The server parameters, typically from $_SERVER.
     * @return array An array containing the detected host and port (host, port|null).
     */
    private static function detectHostPort(array $s): array
    {
        // 1) RFC 7239 Forwarded: host="example.com:8443"
        if ($fp = self::detectForwardedHost($s)) {
            [$h, $p] = $fp;

            // Complement with X-Forwarded-Port if allowed and not present
            if ($p === null && (Request::getProxyHeaderFlags() & Request::HEADER_X_FORWARDED_PORT) !== 0) {
                if (!empty($s['HTTP_X_FORWARDED_PORT'])) {
                    $first = (int)trim(explode(',', (string)$s['HTTP_X_FORWARDED_PORT'])[0]);
                    if ($first > 0 && $first <= 65535) {
                        $p = $first;
                    }
                }
            }
            return [$h, $p];
        }

        // 2) Direct socket view
        $rawHost = $s['HTTP_HOST'] ?? $s['SERVER_NAME'] ?? 'localhost';
        [$host, $port] = self::splitHostPort($rawHost);

        if ($port === null && !empty($s['SERVER_PORT'])) {
            $port = (int)$s['SERVER_PORT'];
        }
        return [$host, $port];
    }

    /**
     * Returns [host, port|null] parsed from Forwarded or X-Forwarded-Host
     * headers. If no headers are present, returns null.
     *
     * @see https://tools.ietf.org/html/rfc7239#section-5.3
     * @see https://tools.ietf.org/html/rfc7239#section-5.5
     * @see https://tools.ietf.org/html/rfc7239#section-5.5.2
     */
    private static function detectForwardedHost(array $s): ?array
    {
        // Forwarded: by=...;for=...;host=example.com:8443;proto=https
        if ((Request::getProxyHeaderFlags() & Request::HEADER_FORWARDED) !== 0) {
            if (!empty($s['HTTP_FORWARDED'])) {
                $first = explode(',', (string)$s['HTTP_FORWARDED'])[0];
                if (preg_match('/host=(?:"([^"]+)"|([^;,\s]+))/i', $first, $m)) {
                    $hp = $m[1] !== '' ? $m[1] : trim($m[2]);
                    return self::splitHostPort($hp);
                }
            }
        }

        // X-Forwarded-Host: a.example, b.example
        if ((Request::getProxyHeaderFlags() & Request::HEADER_X_FORWARDED_HOST) !== 0) {
            if (!empty($s['HTTP_X_FORWARDED_HOST'])) {
                $first = trim(explode(',', (string)$s['HTTP_X_FORWARDED_HOST'])[0]);
                return self::splitHostPort($first);
            }
        }

        return null;
    }

    /**
     * Splits a host string into host and port components.
     *
     * Given a hostname (or IP address) optionally followed by a colon and a port number,
     * returns an array with two elements: the hostname (or IP address) and the port number.
     *
     * Examples:
     *   - "[::1]"    => ["[::1]", null]
     *   - "[::1]:8443" => ["[::1]", 8443]
     *   - "example.com" => ["example.com", null]
     *   - "example.com:8443" => ["example.com", 8443]
     *   - "example.com:foo" => ["example.com:foo", null]
     */
    private static function splitHostPort(string $v): array
    {
        $v = trim($v);
        if ($v === '') {
            return ['', null];
        }

        // 1) Bracketed IPv6: "[::1]" or "[::1]:8443"
        if ($v[0] === '[') {
            if (preg_match('/^\[(?<host>[^\]]+)\](?::(?<port>\d{1,5}))?$/', $v, $m)) {
                $host = '[' . $m['host'] . ']';
                $port = isset($m['port']) ? self::normPort($m['port']) : null;
                return [$host, $port];
            }
            // malformed → keep as host (don’t invent a port)
            return [$v, null];
        }

        // 2) Non-IPv6 (no brackets). Treat as "host[:port]" only when there is exactly one colon and a numeric port.
        if (preg_match('/^(?<host>[^:]+):(?<port>\d{1,5})$/', $v, $m)) {
            $port = self::normPort($m['port']);
            return [$m['host'], $port];
        }

        // Multiple colons (unbracketed IPv6) or no port suffix → host only.
        return [$v, null];
    }

    /**
     * Normalizes a port number string to an integer.
     *
     * @param string $p the port number string
     * @return int|null the normalized port number, or null if invalid
     *
     * The port number is only considered valid if it is a positive integer
     * between 1 and 65535, inclusive.
     */
    private static function normPort(string $p): ?int
    {
        $i = (int)$p;
        return ($i > 0 && $i <= 65535) ? $i : null;
    }


    /**
     * Retrieves the request URI from the given server parameters.
     *
     * Returns the value of the 'REQUEST_URI' key in the given server parameters, or
     * a single forward slash character ('/') if the key is not present.
     *
     * @param array $s The server parameters.
     * @return string The request URI.
     */
    private static function detectRequestUri(array $s): string
    {
        return $s['REQUEST_URI'] ?? '/';
    }

    /**
     * Reconstructs the full URL from individual components.
     *
     * Suppresses the port number if it matches the default port for the given scheme.
     *
     * @param string $scheme The URL scheme (e.g. "http", "https").
     * @param string $host The hostname or IP address.
     * @param int|null $port The port number, or null if it should be omitted.
     * @param string $reqUri The request URI.
     * @return string The full URL.
     */
    private static function buildFullUrl(string $scheme, string $host, ?int $port, string $reqUri): string
    {
        $default = self::getDefaultPortForScheme($scheme);
        if ($port !== null && $default !== null && $port === $default) {
            $port = null;
        }
        return $scheme . '://' . $host . ($port ? ":{$port}" : '') . $reqUri;
    }

    /**
     * Returns the default port number for the given scheme.
     *
     * @param string $scheme The scheme to get the default port for.
     * @return int|null The default port number, or null if no default port is known.
     */
    private static function getDefaultPortForScheme(string $scheme): ?int
    {
        return $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
    }

    /**
     * Returns a string representation of the URI object.
     *
     * @return string The string representation of the URI object.
     */
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

    /**
     * Retrieves the scheme component of the URI (e.g. "https" or "http").
     *
     * @return string The scheme component of the URI.
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Retrieves the user information component of the URI.
     *
     * @return string The user information component of the URI, e.g. "username:password".
     */
    public function getUserInfo(): string
    {
        return $this->user === '' ? '' :
            ($this->pass === '' ? $this->user : "{$this->user}:{$this->pass}");
    }

    /**
     * Retrieves the host component of the URI.
     *
     * @return string The hostname or IP address of the URI.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Retrieves the port component of the URI.
     *
     * If the port is empty, null will be returned.
     *
     * @return int|null The port component of the URI if set, or null if empty.
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Retrieves the authority component of the URI.
     *
     * The authority component consists of the userinfo, host and port.
     * If the userinfo is empty, only the host and port will be returned.
     * If the port is null, only the host will be returned.
     *
     * @return string The authority component of the URI.
     */
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

    /**
     * Returns the path of the URI.
     *
     * The path is the part of the URI between the authority and the query string.
     * If the URI does not have a path, an empty string is returned.
     *
     * @return string The path of the URI
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Returns the query string of the URI.
     *
     * The query string is the part of the URI after the '?'.
     * If the URI does not have a query string, an empty string is returned.
     *
     * @return string The query string of the URI
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Returns the fragment of the URI.
     *
     * The fragment is the part of the URI after the '#'.
     * If the URI does not have a fragment, an empty string is returned.
     *
     * @return string The fragment of the URI
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * Returns a new Uri with the given scheme.
     *
     * If the given scheme is the same as the current scheme, the original Uri is returned.
     *
     * If the given scheme is different from the current scheme, a new Uri is returned with the given scheme.
     * The port is set to null if it is equal to the default port for the given scheme.
     *
     * @param string $scheme The scheme to use (e.g. "http", "https")
     * @return Uri A new Uri with the given scheme
     */
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

    /**
     * Returns a new Uri with the given user information.
     *
     * If the given user information is the same as the current user information,
     * the original Uri is returned.
     *
     * @param string $user
     * @param string|null $password
     * @return Uri
     */
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

    /**
     * Returns a new Uri with the given host.
     *
     * If the given host is empty, the host is omitted from the URI.
     * If the given host is the same as the current host, the original Uri is returned.
     *
     * @param string $host
     * @return Uri
     */
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

    /**
     * Returns a new Uri with the given port.
     * If the given port is the same as the default port for the current scheme,
     * the port is omitted from the URI.
     *
     * @param int|null $port
     * @return Uri
     * @throws InvalidArgumentException if the port is invalid (< 1 or > 65535)
     */
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

    /**
     * Returns a new Uri with the given path.
     *
     * The path is normalized by calling filterPath() on it.
     * If the normalized path is the same as the current one, the original Uri is returned.
     *
     * @param string $path The path to use.
     * @return Uri A new Uri with the given path.
     */
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

    /**
     * Returns a new Uri with the given query string.
     *
     * The query string is normalized by calling filterQuery() on it.
     * If the normalized query string is the same as the current one, the original Uri is returned.
     *
     * @param string $query The query string to use.
     * @return Uri A new Uri with the given query string.
     */
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

    /**
     * Returns a new Uri with the given fragment.
     *
     * The fragment is normalized by calling filterFragment() on it.
     * If the normalized fragment is the same as the current one, the original Uri is returned.
     *
     * @param string $fragment The fragment to set
     * @return Uri A new Uri with the given fragment
     */
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

    /**
     * Normalize a query-string.
     *
     * This function takes a raw query-string and returns a new one with:
     *   •   alpha-sorted keys
     *   •   duplicate keys preserved in order
     *   •   RFC 3986 escaping
     *
     * Useful for normalizing URLs and checking for equality.
     *
     * @param string $qs The query-string to normalize
     * @return string The normalized query-string
     */
    public static function normalizeQueryString(string $qs): string
    {
        if ($qs === '') {
            return '';
        }

        // Manual split is ~2× faster than parse_str() + ksort() for hot paths
        $pairs = preg_split('/[&;]+/', $qs, -1, PREG_SPLIT_NO_EMPTY);
        $bucket = [];

        foreach ($pairs as $p) {
            [$k, $v] = array_pad(explode('=', $p, 2), 2, '');
            // preserve duplicates: bucket[key][] = value
            $bucket[rawurldecode($k)][] = rawurldecode($v);
        }

        ksort($bucket, SORT_STRING);

        $out = '';
        foreach ($bucket as $k => $values) {
            $ek = rawurlencode($k);
            foreach ($values as $v) {
                $ev = rawurlencode($v);
                $out .= $ek . ($ev === '' ? '' : '=' . $ev) . '&';
            }
        }

        return rtrim($out, '&');
    }

    /**
     * Converts a host name to an ASCII-compatible string.
     *
     * If the host name is an IPv6 address with a zone, it is left unchanged.
     * If the IDN library is available, it is used to convert the host name to an ASCII-compatible string.
     * If the IDN library is not available, the host name is converted to lowercase.
     *
     * The result is cached to avoid redundant computation.
     *
     * @param string $host The host name to convert.
     * @return string The ASCII-compatible host name.
     * @throws InvalidArgumentException If the host name cannot be converted to an ASCII-compatible string.
     */
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

    /**
     * Apply RFC 3986 §5.2.4 dot-segment removal to a path segment.
     *
     * This process removes unnecessary dot segments from a path, and is
     * necessary to ensure that URIs are properly normalized.
     *
     * @param string $path The path segment to filter
     * @return string The filtered path segment
     */
    private function filterPath(string $path): string
    {
        do {
            $old = $path;
            $path = preg_replace('#(/\.?/)#', '/', $path);        // "/./" or "//"
            $path = preg_replace('#/(?!\.\.)[^/]+/\.\./#', '/', $path); // "x/../"
            $path = preg_replace('#^/\.\.(?=/|$)#', '/', $path);  // leading "/../"
        } while ($path !== $old);

        return $path === '' ? '/' : $path;
    }

    /**
     * Trim a query string to ensure it doesn't begin with a '?'
     * character.
     *
     * @param string $query The query string to trim
     * @return string The trimmed query string
     */
    private function filterQuery(string $query): string
    {
        return ltrim($query, '?');
    }

    /**
     * Trim a fragment string (e.g. "#anchor") to ensure it begins with a '#'
     * character.
     *
     * @param string $fragment The fragment string to trim
     * @return string The trimmed fragment string
     */
    private function filterFragment(string $fragment): string
    {
        return ltrim($fragment, '#');
    }

    /**
     * Get the default port for the given scheme.
     *
     * @param string $scheme One of 'http' or 'https'
     * @return int|null The default port for the scheme, or null if unknown
     */
    private function defaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null
        };
    }
}
