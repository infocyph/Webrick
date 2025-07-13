<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Request\Support\HeaderBag;

/**
 * Immutable builder for the Cache-Control header.
 *
 * ```php
 * $cc = CacheControl::new()
 *        ->public()->maxAge(60)
 *        ->staleWhileRevalidate(30);
 *
 * $resp = $resp->withHeader('Cache-Control', $cc);
 * ```
 *
 * No reflection, no regex – pure string concat.
 */
final class CacheControl implements \Stringable
{
    /** @var array<string,string|null> token => value|null */
    private array $parts = [];

    private function __construct(array $existing = [])
    {
        $this->parts = $existing;
    }

    /* --------------------------------------------------------------
     * Factory
     * ------------------------------------------------------------ */
    public static function fromHeaderBag(HeaderBag $bag): self
    {
        $line = $bag->getHeaderLine('Cache-Control') ?? '';
        if ($line === '') {
            return new self();
        }

        $parsed = [];
        foreach (explode(',', $line) as $token) {
            [$name, $val] = array_map('trim', explode('=', $token, 2) + [1 => null]);
            $parsed[$name] = $val;
        }
        return new self($parsed);
    }

    public static function new(): self
    {
        return new self();
    }

    /* --------------------------------------------------------------
     * Directive helpers (common ones)
     * ------------------------------------------------------------ */
    public function public(): self
    {
        return $this->with('public');
    }

    public function private(): self
    {
        return $this->with('private');
    }

    public function noCache(): self
    {
        return $this->with('no-cache');
    }

    public function noStore(): self
    {
        return $this->with('no-store');
    }

    public function mustRevalidate(): self
    {
        return $this->with('must-revalidate');
    }

    public function proxyRevalidate(): self
    {
        return $this->with('proxy-revalidate');
    }

    public function immutable(): self
    {
        return $this->with('immutable');
    }

    public function maxAge(int $seconds): self
    {
        return $this->with('max-age', $seconds);
    }

    public function sMaxAge(int $seconds): self
    {
        return $this->with('s-maxage', $seconds);
    }

    public function staleWhileRevalidate(int $seconds): self
    {
        return $this->with('stale-while-revalidate', $seconds);
    }

    public function staleIfError(int $seconds): self
    {
        return $this->with('stale-if-error', $seconds);
    }

    /* --------------------------------------------------------------
     * Internal immutable mutator
     * ------------------------------------------------------------ */
    private function with(string $token, int|string|null $value = null): self
    {
        $x = clone $this;
        $x->parts[$token] = $value === null ? null : (string)$value;
        // RFC: “public” and “private” are mutually exclusive – last one wins
        if ($token === 'public') {
            unset($x->parts['private']);
        } elseif ($token === 'private') {
            unset($x->parts['public']);
        }
        return $x;
    }

    /* --------------------------------------------------------------
     * Render
     * ------------------------------------------------------------ */
    public function __toString(): string
    {
        $out = [];
        foreach ($this->parts as $k => $v) {
            $out[] = $v === null ? $k : "$k=$v";
        }
        return implode(', ', $out);
    }
}
