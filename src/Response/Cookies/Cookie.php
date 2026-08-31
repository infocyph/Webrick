<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Cookies;

/** Immutable RFC 6265-style cookie with security-prefix invariants. */
final class Cookie implements \Stringable
{
    private const string INVALID_DOMAIN = '/[\x00-\x20\x7F;,]/';

    private const string INVALID_PATH = '/[\x00-\x1F\x7F;]/';

    private const string NAME_RX = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/';

    private function __construct(
        public string $name,
        private string $value = '',
        private ?int $expires = null,
        private string $path = '/',
        private ?string $domain = null,
        private bool $secure = true,
        private bool $httpOnly = true,
        private ?string $sameSite = 'Lax',
        private bool $partitioned = false,
    ) {}

    public function __toString(): string
    {
        $this->assertInvariants();
        $parts = [$this->name . '=' . rawurlencode($this->value), 'Path=' . $this->path];
        if ($this->domain !== null && $this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }
        if ($this->expires !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $this->expires) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $this->expires - time());
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($this->sameSite !== null) {
            $parts[] = 'SameSite=' . $this->sameSite;
        }
        if ($this->partitioned) {
            $parts[] = 'Partitioned';
        }

        return implode('; ', $parts);
    }

    public static function make(
        string $name,
        string $value = '',
        bool $secure = true,
        bool $httpOnly = true,
        ?string $sameSite = 'Lax',
    ): self {
        if (!preg_match(self::NAME_RX, $name)) {
            throw new \InvalidArgumentException("Invalid cookie name: {$name}");
        }
        if ($sameSite !== null) {
            $sameSite = ucfirst(strtolower($sameSite));
            if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
                throw new \InvalidArgumentException('SameSite must be Lax|Strict|None.');
            }
        }

        $cookie = new self($name, $value, secure: $secure, httpOnly: $httpOnly, sameSite: $sameSite);
        $cookie->assertInvariants();

        return $cookie;
    }

    public function domain(string $domain): self
    {
        if (preg_match(self::INVALID_DOMAIN, $domain) === 1) {
            throw new \InvalidArgumentException('Cookie domain contains invalid characters.');
        }
        if (str_starts_with($this->name, '__Host-') && $domain !== '') {
            throw new \InvalidArgumentException('__Host- cookies must not declare Domain.');
        }

        $cookie = clone $this;
        $cookie->domain = $domain === '' ? null : strtolower($domain);
        $cookie->assertInvariants();

        return $cookie;
    }

    public function domainValue(): ?string
    {
        return $this->domain;
    }

    public function expire(): self
    {
        $cookie = clone $this;
        $cookie->expires = time() - 86400;
        $cookie->value = '';

        return $cookie;
    }

    public function expires(\DateTimeInterface $when): self
    {
        $cookie = clone $this;
        $cookie->expires = $when->getTimestamp();

        return $cookie;
    }

    public function forever(): self
    {
        $cookie = clone $this;
        $cookie->expires = time() + 60 * 60 * 24 * 365 * 5;

        return $cookie;
    }

    public function httpOnly(bool $on = true): self
    {
        $cookie = clone $this;
        $cookie->httpOnly = $on;

        return $cookie;
    }

    public function identity(): string
    {
        return $this->name . "\0" . strtolower($this->domain ?? '') . "\0" . $this->path;
    }

    public function maxAge(int $seconds): self
    {
        $cookie = clone $this;
        $cookie->expires = time() + max(0, $seconds);

        return $cookie;
    }

    public function partitioned(bool $on = true): self
    {
        $cookie = clone $this;
        $cookie->partitioned = $on;
        if ($on) {
            $cookie->secure = true;
        }
        $cookie->assertInvariants();

        return $cookie;
    }

    public function path(string $path): self
    {
        if ($path === '' || preg_match(self::INVALID_PATH, $path) === 1) {
            throw new \InvalidArgumentException('Cookie path is empty or contains invalid characters.');
        }
        if (str_starts_with($this->name, '__Host-') && $path !== '/') {
            throw new \InvalidArgumentException('__Host- cookies must use Path=/.');
        }

        $cookie = clone $this;
        $cookie->path = $path;
        $cookie->assertInvariants();

        return $cookie;
    }

    public function pathValue(): string
    {
        return $this->path;
    }

    public function sameSite(string $mode): self
    {
        $mode = ucfirst(strtolower($mode));
        if (!in_array($mode, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException('SameSite must be Lax|Strict|None.');
        }

        $cookie = clone $this;
        $cookie->sameSite = $mode;
        if ($mode === 'None') {
            $cookie->secure = true;
        }
        $cookie->assertInvariants();

        return $cookie;
    }

    public function secure(bool $on = true): self
    {
        if (!$on && (str_starts_with($this->name, '__Host-') || str_starts_with($this->name, '__Secure-'))) {
            throw new \InvalidArgumentException('Cookie security prefix requires Secure.');
        }
        if (!$on && ($this->sameSite === 'None' || $this->partitioned)) {
            throw new \InvalidArgumentException('SameSite=None and Partitioned cookies require Secure.');
        }

        $cookie = clone $this;
        $cookie->secure = $on;
        $cookie->assertInvariants();

        return $cookie;
    }

    private function assertInvariants(): void
    {
        if (str_starts_with($this->name, '__Secure-') && !$this->secure) {
            throw new \InvalidArgumentException('__Secure- cookies require Secure.');
        }
        if (str_starts_with($this->name, '__Host-')) {
            if (!$this->secure || $this->path !== '/' || $this->domain !== null) {
                throw new \InvalidArgumentException('__Host- cookies require Secure, Path=/, and no Domain.');
            }
        }
        if ($this->sameSite === 'None' && !$this->secure) {
            throw new \InvalidArgumentException('SameSite=None cookies require Secure.');
        }
        if ($this->partitioned && !$this->secure) {
            throw new \InvalidArgumentException('Partitioned cookies require Secure.');
        }
    }
}
