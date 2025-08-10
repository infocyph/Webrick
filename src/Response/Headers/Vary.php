<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * Builder for the **Vary** response header.
 * - De-dupes tokens
 * - Canonicalises case (dash-aware)
 * - Understands the "*" wildcard (stands alone)
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
            $t = self::norm($t);
            if ($t === '') {
                continue;
            }
            if ($t === '*') {
                $v->tokens = ['*' => true];
                break; // "*" must not be combined with others
            }
            $v->tokens[$t] = true;
        }
        return $v;
    }

    /* ------------------------------------------------------------------ */

    public function add(string ...$headers): self
    {
        $x = clone $this;

        foreach ($headers as $h) {
            $h = self::norm($h);
            if ($h === '') {
                continue;
            }
            if ($h === '*') {
                // "*" overrides everything else
                $x->tokens = ['*' => true];
                return $x;
            }
            if (!isset($x->tokens['*'])) {
                $x->tokens[$h] = true;
            }
        }

        return $x;
    }

    public function remove(string ...$headers): self
    {
        $x = clone $this;
        foreach ($headers as $h) {
            $h = self::norm($h);
            if ($h === '') {
                continue;
            }
            unset($x->tokens[$h]);
        }
        return $x;
    }

    public function __toString(): string
    {
        if (isset($this->tokens['*'])) {
            return '*';
        }
        $names = array_map(self::canonicalHeader(...), array_keys($this->tokens));
        return implode(', ', $names);
    }

    /* ------------------------------------------------------------------ */

    private static function norm(string $h): string
    {
        return strtolower(trim($h));
    }

    private static function canonicalHeader(string $lower): string
    {
        // e.g. "accept-encoding" → "Accept-Encoding"
        return ucwords($lower, '-');
    }
}
