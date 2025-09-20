<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Cookies;

/**
 * Immutable cookie value-object.
 *
 * `$cookie = Cookie::make('theme','dark')->httpOnly()->secure();`
 * `$headerLine = (string)$cookie;`
 */
final class Cookie implements \Stringable
{
    /** rfc6265 allowed chars for name */
    private const NAME_RX = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/';

    /**
     * Initializes a new Cookie instance.
     *
     * @param string $name Cookie name, must match the rfc6265 allowed chars.
     * @param string $value [optional] Cookie value, defaults to an empty string.
     * @param int|null $expires [optional] Unix epoch timestamp for the cookie's expiration time, defaults to null (no expiration).
     * @param string $path [optional] Cookie path, defaults to '/'.
     * @param string|null $domain [optional] Cookie domain, defaults to null (current domain).
     * @param bool $secure [optional] Whether the cookie should be transmitted over a secure channel, defaults to true.
     * @param bool $httpOnly [optional] Whether the cookie should be restricted to HTTP only, defaults to true.
     * @param string $sameSite [optional] Same-site policy for the cookie, one of 'Lax', 'Strict', or 'None', defaults to 'Lax'.
     */
    private function __construct(
        public string $name,
        private string $value = '',
        private ?int $expires = null,       // unix epoch
        private string $path = '/',
        private ?string $domain = null,
        private bool $secure = true,
        private bool $httpOnly = true,
        private string $sameSite = 'Lax',      // Lax|Strict|None
    ) {
    }

    /**
     * Factory method to create a new Cookie instance.
     *
     * @param string $name Valid cookie name (rfc6265)
     * @param string $value Optional cookie value (default: empty string)
     * @return static
     * @throws \InvalidArgumentException If the cookie name is invalid
     */
    public static function make(string $name, string $value = ''): self
    {
        if (!preg_match(self::NAME_RX, $name)) {
            throw new \InvalidArgumentException("Invalid cookie name: {$name}");
        }
        return new self($name, $value);
    }

    /**
     * Set the cookie's expiration time to 5 years from now.
     * After this deadline, the cookie will be deleted by the client.
     * @return self
     */
    public function forever(): self
    {
        $x = clone $this;
        $x->expires = time() + 60 * 60 * 24 * 365 * 5; // 5y
        return $x;
    }

    /**
     * Expiration sets the cookie to expire immediately.
     * Value is set to an empty string to prevent any accidental usage.
     *
     * @return self
     */
    public function expire(): self
    {
        $x = clone $this;
        $x->expires = time() - 86400;
        $x->value = '';
        return $x;
    }

    /**
     * Sets the path of the cookie.
     *
     * If set to a non-empty string, the cookie will only be accessible when the
     * path of the request matches the specified path.
     * If set to null, the cookie will be accessible for all paths.
     * If set to an empty string, the cookie will be accessible for the root path only.
     *
     * @param string $p The path to set for the cookie.
     * @return self
     */
    public function path(string $p): self
    {
        $y = clone $this;
        $y->path = $p;
        return $y;
    }

    /**
     * Sets the domain of the cookie.
     *
     * If set to a non-empty string, the cookie will only be sent to the specified domain.
     * If set to null, the cookie will be sent to the current domain.
     * If set to an empty string, the cookie will be sent to all domains.
     *
     * @param string $d The domain to set for the cookie.
     * @return self
     */
    public function domain(string $d): self
    {
        $y = clone $this;
        $y->domain = $d;
        return $y;
    }

    /**
     * Sets the Secure flag of the cookie.
     * If set to true, the cookie will only be transmitted over a secure channel (i.e., a channel protected by SSL/TLS).
     * @param bool $on If true, sets the Secure flag to true. If false, sets it to false.
     * @return self
     */
    public function secure(bool $on = true): self
    {
        $y = clone $this;
        $y->secure = $on;
        return $y;
    }

    /**
     * Sets the HttpOnly flag of the cookie.
     * If set to true, the cookie will only be transmitted over a secure channel (i.e., a channel protected by SSL/TLS).
     * @param bool $on If true, sets the HttpOnly flag to true. If false, sets it to false.
     * @return self
     */
    public function httpOnly(bool $on = true): self
    {
        $y = clone $this;
        $y->httpOnly = $on;
        return $y;
    }

    /**
     * Set the SameSite attribute of the cookie.
     *
     * @param string $mode One of 'Lax', 'Strict', or 'None'.
     * @return self
     * @throws \InvalidArgumentException If $mode is not one of 'Lax', 'Strict', or 'None'.
     */
    public function sameSite(string $mode): self
    {
        $mode = ucfirst(strtolower($mode));
        if (!in_array($mode, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException('SameSite must be Lax|Strict|None');
        }
        $y = clone $this;
        $y->sameSite = $mode;
        return $y;
    }

    /**
     * Sets the cookie's expiration time to the given DateTimeInterface object.
     *
     * Note that the expiration time is represented as a Unix timestamp, which is the number of seconds elapsed since January 1, 1970, 00:00:00 (UTC) time.
     *
     * @param \DateTimeInterface $when The desired expiration time of the cookie.
     * @return self The instance of the cookie with the expiration time set.
     */
    public function expires(\DateTimeInterface $when): self
    {
        $x = clone $this;
        $x->expires = $when->getTimestamp();
        return $x;
    }

    /**
     * Set the cookie's max-age to the given number of seconds.
     * Note that max-age is relative to the current time, so setting it to 0 effectively deletes the cookie.
     * @param int $seconds The number of seconds until the cookie expires.
     * @return self
     */
    public function maxAge(int $seconds): self
    {
        $x = clone $this;
        $x->expires = time() + max(0, $seconds);
        return $x;
    }

    /**
     * Returns a string representation of the cookie in the format
     * "Set-Cookie: <name>=<value>; Path=<path>; Domain=<domain>; Expires=<date>; Max-Age=<seconds>; Secure; HttpOnly; SameSite=<mode>"
     *
     * @return string A string representation of the cookie.
     */
    public function __toString(): string
    {
        $parts = ["$this->name=" . rawurlencode($this->value)];
        $parts[] = 'Path=' . $this->path;

        if ($this->domain) {
            $parts[] = 'Domain=' . $this->domain;
        }
        if ($this->expires) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $this->expires) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $this->expires - time());
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->sameSite === 'None' && !$this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }
}
