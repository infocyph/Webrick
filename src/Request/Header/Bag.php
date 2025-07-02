<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Header;

use Infocyph\ArrayKit\Collection\Collection;
use Psr\Http\Message\ServerRequestInterface;
use Stringable;
use Traversable;

/**
 * Immutable, case-insensitive header bag.
 *
 * Works both stand-alone (`Bag::from($array)`) and as a thin wrapper for any
 * PSR-7 request (`Bag::fromRequest($req)`).
 *
 * Exposes typed helpers for Accept, Content-*, Conditional/Range headers.
 */
readonly class Bag implements \IteratorAggregate, Stringable
{
    public static function from(array $headers): self
    {
        /** @var array<string,list<string>> $canon */
        $canon = [];
        foreach ($headers as $name => $value) {
            $canon[self::canonical($name)] = \is_array($value) ? $value : [(string) $value];
        }

        return new self(Collection::from($canon));
    }

    public static function fromRequest(ServerRequestInterface $req): self
    {
        return self::from($req->getHeaders());
    }

    /* -------------------------------------------------------------- */

    public function getIterator(): Traversable
    {
        yield from $this->all;
    }

    public function __toString(): string
    {
        return json_encode($this->all, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /* -------------------------------------------------------------- */
    /** @return list<string>|string|null */
    public function get(string $name): array|string|null
    {
        return $this->all[self::canonical($name)] ?? null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function accept(): Accept
    {
        return new Accept((string) ($this->get('Accept')[0] ?? ''));
    }

    public function content(): Content
    {
        return new Content(
            (string) ($this->get('Content-Type')[0] ?? ''),
            (string) ($this->get('Content-Length')[0] ?? ''),
            (string) ($this->get('Content-MD5')[0] ?? '')
        );
    }

    public function conditional(): Conditional
    {
        return new Conditional(
            (string) ($this->get('If-Match')[0] ?? ''),
            (string) ($this->get('If-None-Match')[0] ?? ''),
            (string) ($this->get('If-Modified-Since')[0] ?? ''),
            (string) ($this->get('If-Unmodified-Since')[0] ?? ''),
            (string) ($this->get('Range')[0] ?? ''),
            (string) ($this->get('Prefer')[0] ?? '')
        );
    }

    /* -------------------------------------------------------------- */
    private function __construct(private Collection $all) {}

    private static function canonical(string $name): string
    {
        return \ucwords(\strtolower($name), '-');
    }
}
