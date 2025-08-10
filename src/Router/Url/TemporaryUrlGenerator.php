<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Infocyph\Webrick\Router\Route\Collection;
use InvalidArgumentException;

/**
 * Generates temporary signed URLs with a default time-to-live.
 */
final class TemporaryUrlGenerator extends SignedUrlGenerator
{
    private int $defaultTtl;

    /**
     * @param string $baseUri e.g. "https://api.example.com"
     * @param Collection $routes Your Route collection
     * @param string $secret HMAC secret
     * @param int $defaultTtl Default TTL in seconds (must be ≥1)
     *
     * @throws InvalidArgumentException if $defaultTtl < 1
     */
    public function __construct(
        string $baseUri,
        Collection $routes,
        string $secret,
        int $defaultTtl = 900, // 15 minutes
    )
    {
        // ── sanity: forbid query / fragment on the base URI ─────────────
        $parts = \parse_url($baseUri);
        if ($parts === false || isset($parts['query'], $parts['fragment'])) {
            throw new InvalidArgumentException(
                'baseUri must not contain query or fragment components',
            );
        }

        // strip trailing “/” so UrlGenerator always does `$baseUri . '/' . …`
        $clean = \rtrim($baseUri, '/');
        parent::__construct($clean, $routes, $secret);

        if ($defaultTtl < 1) {
            throw new InvalidArgumentException('defaultTtl must be a positive integer.');
        }
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Build a **temporary** signed URL.
     *
     * If you don’t supply `$ttl`, it falls back to `$this->defaultTtl`.
     *
     * @param non-empty-string $name Route name
     * @param array<string,mixed> $params Path parameters
     * @param array<string,mixed> $query Additional query parameters
     * @param int|null $ttl Override TTL in seconds
     * @param bool $absolute Prepend baseUri?
     *
     * @return string
     */
    public function temporary(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = true,
    ): string {
        return $this->signed(
            name: $name,
            params: $params,
            query: $query,
            ttl: $ttl ?? $this->defaultTtl,
            absolute: $absolute,
        );
    }
}
