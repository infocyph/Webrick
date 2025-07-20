<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * Builder for the **Vary** response header.
 * Guarantees de-duped, canonicalised tokens.
 */
final class Vary implements \Stringable
{
    /** @var array<string,bool> */
    private array $tokens = [];

    public static function new(): self
    {
        return new self();
    }

    /** Merge an existing raw Vary header. */
    public static function fromString(string $raw): self
    {
        $v = new self();
        foreach (explode(',', $raw) as $t) {
            $v->tokens[strtolower(trim($t))] = true;
        }
        return $v;
    }

    /* ------------------------------------------------------------------ */

    public function add(string ...$headers): self
    {
        $x = clone $this;
        foreach ($headers as $h) {
            $x->tokens[strtolower(trim($h))] = true;
        }
        return $x;
    }

    public function remove(string ...$headers): self
    {
        $x = clone $this;
        foreach ($headers as $h) {
            unset($x->tokens[strtolower(trim($h))]);
        }
        return $x;
    }

    public function __toString(): string
    {
        return implode(', ', array_map('ucwords', array_keys($this->tokens)));
    }
}
