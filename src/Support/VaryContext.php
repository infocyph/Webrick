<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

/** Request-local mutable Vary token accumulator; never shared between requests. */
final class VaryContext
{
    public const string ATTRIBUTE = 'webrick.vary_context';

    /** @var array<string,string> */
    private array $tokens = [];

    public function add(string ...$headers): void
    {
        foreach ($headers as $header) {
            foreach (explode(',', $header) as $token) {
                $canonical = self::canonical($token);
                if ($canonical !== '') {
                    $this->tokens[strtolower($canonical)] = $canonical;
                }
            }
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_values($this->tokens);
    }

    public function clear(): void
    {
        $this->tokens = [];
    }

    private static function canonical(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        return implode('-', array_map(
            static fn(string $part): string => $part === '' ? '' : ucfirst(strtolower($part)),
            explode('-', $token),
        ));
    }
}
