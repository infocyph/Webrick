<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Request\Parser;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Value-object + helper for HTTP cookies (RFC 6265bis, 2023-12-06 draft-19).
 *
 * • `fromRequestHeader()`   – parse a raw “Cookie:” header into name ↔ value pairs
 * • `fromSetCookieHeader()` – parse a single “Set-Cookie:” line into a Cookie object
 * • `__toString()`          – render back to Set-Cookie format (for response helpers)
 *
 * The class is immutable / readonly (PHP 8.2+) and PSR-12 compliant.
 */
final class Cookie
{
    /* ------------------------------------------------------------
       ctor – all fields readonly so the object is immutable
       ------------------------------------------------------------ */
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly ?DateTimeImmutable $expires = null,
        public readonly ?int $maxAge                = null,
        public readonly ?string $domain             = null,
        public readonly ?string $path               = '/',
        public readonly bool $secure                = false,
        public readonly bool $httpOnly              = false,
        public readonly ?string $sameSite           = null    // Lax | Strict | None
    ) {
        if ($name === '' || strpbrk($name, "=,; \t\r\n\013\014") !== false) {
            throw new InvalidArgumentException('Invalid cookie name');
        }
    }

    /* ============================================================
       1.  Client-side “Cookie: …” header  →  array<string,string>
       ============================================================ */
    public static function fromRequestHeader(string $header): array
    {
        $out = [];
        // Split on semicolons, but ignore “; SameSite=None” etc.
        foreach (preg_split('/\s*;\s*/', trim($header)) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$n, $v] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
            // Cookies *can* be sent without a value – treat as empty string
            $out[$n] = urldecode($v);
        }

        return $out;
    }

    /* ============================================================
       2.  Server-side “Set-Cookie: …” header → Cookie object
       ============================================================ */
    public static function fromSetCookieHeader(string $header): self
    {
        $segments = preg_split('/\s*;\s*/', trim($header));
        if ($segments === false || $segments === []) {
            throw new InvalidArgumentException('Empty Set-Cookie header');
        }

        // ---- name=value ------------------------------------------------
        [$name, $val] = array_map('trim', explode('=', array_shift($segments), 2) + [1 => '']);
        $name = $name === '' ? throw new InvalidArgumentException('Cookie name missing') : $name;

        // ---- attributes ------------------------------------------------
        $exp   = null;
        $max   = null;
        $dom   = null;
        $path  = '/';
        $sec   = false;
        $http  = false;
        $site  = null;

        foreach ($segments as $attr) {
            [$k, $v] = array_map('trim', explode('=', $attr, 2) + [1 => null]);
            $kLow = strtolower($k);

            match ($kLow) {
                'expires'  => $exp  = ($v !== null) ? new DateTimeImmutable($v) : null,
                'max-age'  => $max  = ($v !== null) ? (int) $v : null,
                'domain'   => $dom  = strtolower((string) $v),
                'path'     => $path = (string) $v ?: '/',
                'secure'   => $sec  = true,
                'httponly' => $http = true,
                'samesite' => $site = ucfirst(strtolower((string) $v ?: 'Lax')),
                default    => null
            };
        }

        return new self($name, $val, $exp, $max, $dom, $path, $sec, $http, $site);
    }

    /* ============================================================
       3.  Render back to “Set-Cookie” string
       ============================================================ */
    public function __toString(): string
    {
        $parts   = [$this->name . '=' . rawurlencode($this->value)];

        if ($this->expires) {
            $parts[] = 'Expires=' . $this->expires->format('D, d M Y H:i:s \G\M\T');
        }
        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . $this->maxAge;
        }
        if ($this->domain) {
            $parts[] = 'Domain=' . $this->domain;
        }
        if ($this->path) {
            $parts[] = 'Path=' . $this->path;
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($this->sameSite) {
            $parts[] = 'SameSite=' . $this->sameSite;
        }

        return implode('; ', $parts);
    }
}
