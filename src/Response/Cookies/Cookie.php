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

    private function __construct(
        public string  $name,
        private string  $value   = '',
        private ?int    $expires = null,       // unix epoch
        private string  $path    = '/',
        private ?string $domain  = null,
        private bool    $secure  = false,
        private bool    $httpOnly = true,
        private string  $sameSite = 'Lax',      // Lax|Strict|None
    ) {}

    /* ---------- factory helpers ---------------------------------- */
    public static function make(string $name, string $value = ''): self
    {
        if (!preg_match(self::NAME_RX, $name)) {
            throw new \InvalidArgumentException("Invalid cookie name: {$name}");
        }
        return new self($name, $value);
    }

    public function forever(): self
    {
        $x = clone $this;
        $x->expires = time() + 60 * 60 * 24 * 365 * 5; // 5y
        return $x;
    }

    public function expire(): self
    {
        $x = clone $this;
        $x->expires = time() - 86400;
        $x->value   = '';
        return $x;
    }

    public function path(string $p): self      { $y = clone $this; $y->path = $p; return $y; }
    public function domain(string $d): self    { $y = clone $this; $y->domain = $d; return $y; }
    public function secure(bool $on = true): self  { $y = clone $this; $y->secure = $on; return $y; }
    public function httpOnly(bool $on = true): self{ $y = clone $this; $y->httpOnly = $on; return $y; }
    public function sameSite(string $mode): self
    {
        $mode = ucfirst(strtolower($mode));
        if (!in_array($mode, ['Lax','Strict','None'], true)) {
            throw new \InvalidArgumentException('SameSite must be Lax|Strict|None');
        }
        $y = clone $this; $y->sameSite = $mode; return $y;
    }

    /* ---------- output ------------------------------------------- */
    public function __toString(): string
    {
        $parts   = ["{$this->name}=" . rawurlencode($this->value)];
        $parts[] = 'Path=' . $this->path;

        if ($this->domain)  { $parts[] = 'Domain=' . $this->domain; }
        if ($this->expires) { $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $this->expires) . ' GMT'; }
        if ($this->secure)  { $parts[] = 'Secure';  }
        if ($this->httpOnly){ $parts[] = 'HttpOnly';}
        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }
}
