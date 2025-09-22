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

    /**
     * Render the Vary header value.
     *
     * - Returns "*" if wildcard token is present.
     * - Otherwise returns a comma+space separated list of canonicalized token names.
     *
     * @return string Vary header value suitable for sending in responses
     */
    public function __toString(): string
    {
        if (isset($this->tokens['*'])) {
            return '*';
        }
        $names = array_map(self::canonicalHeader(...), array_keys($this->tokens));
        return implode(', ', $names);
    }

    /**
     * Parse a raw Vary header value into a Vary builder.
     *
     * - Splits on commas, trims and normalizes tokens.
     * - Treats "*" as a standalone wildcard (overrides other tokens).
     *
     * @param string $raw Raw Vary header value (possibly comma-separated)
     * @return self Vary instance containing parsed tokens
     */
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

    /**
     * Create a new, empty Vary builder.
     *
     * @return self New Vary instance with no tokens.
     */
    public static function new(): self
    {
        return new self();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Return a new Vary instance with the provided header names added.
     *
     * - Accepts one or more header tokens.
     * - Normalizes tokens, ignores empty entries.
     * - If "*" is added, it overrides all other tokens.
     *
     * @param string ...$headers Header names to add
     * @return self New Vary instance with tokens added
     */
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

    /**
     * Return a new Vary instance with the provided header names removed.
     *
     * - Normalizes tokens before removal.
     * - Ignores empty entries.
     *
     * @param string ...$headers Header names to remove
     * @return self New Vary instance with tokens removed
     */
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

    /**
     * Convert a normalized lower-case token to the canonical header form.
     *
     * Example: "accept-encoding" => "Accept-Encoding"
     *
     * @param string $lower Normalized lower-case token
     * @return string Canonical header token with dash-aware title-casing
     */
    private static function canonicalHeader(string $lower): string
    {
        // e.g. "accept-encoding" → "Accept-Encoding"
        return ucwords($lower, '-');
    }

    /* ------------------------------------------------------------------ */

    /**
     * Normalize a header token for internal storage.
     *
     * - Trims whitespace and lowercases the token.
     *
     * @param string $h Raw token
     * @return string Normalized lower-case token or empty string if input blank
     */
    private static function norm(string $h): string
    {
        return strtolower(trim($h));
    }
}
