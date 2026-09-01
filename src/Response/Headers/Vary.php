<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/** Immutable Vary response-header builder. */
final class Vary implements \Stringable
{
    /** @var array<string,bool> */
    private array $tokens = [];

    public function __toString(): string
    {
        if (isset($this->tokens['*'])) {
            return '*';
        }
        $names = array_map(self::canonicalHeader(...), array_keys($this->tokens));

        return implode(', ', $names);
    }

    public static function fromString(string $raw): self
    {
        $vary = new self();
        foreach (explode(',', $raw) as $token) {
            $token = self::norm($token);
            if ($token === '') {
                continue;
            }
            if ($token === '*') {
                $vary->tokens = ['*' => true];

                break;
            }
            $vary->tokens[$token] = true;
        }

        return $vary;
    }

    public static function new(): self
    {
        return new self();
    }

    public function add(string ...$headers): self
    {
        $copy = clone $this;

        foreach ($headers as $header) {
            $header = self::norm($header);
            if ($header === '') {
                continue;
            }
            if ($header === '*') {
                $copy->tokens = ['*' => true];

                return $copy;
            }
            if (!isset($copy->tokens['*'])) {
                $copy->tokens[$header] = true;
            }
        }

        return $copy;
    }

    public function remove(string ...$headers): self
    {
        $copy = clone $this;
        foreach ($headers as $header) {
            $header = self::norm($header);
            if ($header !== '') {
                unset($copy->tokens[$header]);
            }
        }

        return $copy;
    }

    private static function canonicalHeader(string $lower): string
    {
        return ucwords($lower, '-');
    }

    private static function norm(string $header): string
    {
        $header = trim($header);
        if ($header === '') {
            return '';
        }
        if ($header === '*') {
            return '*';
        }
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $header) !== 1) {
            throw new \InvalidArgumentException("Invalid Vary field-name token: {$header}");
        }

        return strtolower($header);
    }
}
