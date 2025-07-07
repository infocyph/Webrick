<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use Infocyph\Webrick\Router\Route\Collection;

final class TemporaryUrlGenerator extends SignedUrlGenerator
{
    public function __construct(
        string $baseUri,
        Collection $routes,
        string $secret,
        private readonly int $defaultTtl = 900, // 15 minutes
    ) {
        parent::__construct($baseUri, $routes, $secret);
    }

    /**
     * Convenience wrapper – builds a **temporary signed** URL where
     * TTL defaults to $this->defaultTtl unless overridden.
     */
    public function temporary(
        string $name,
        array  $params = [],
        array  $query  = [],
        ?int   $ttl    = null,
        bool   $absolute = true,
    ): string {
        return $this->signed(
            name     : $name,
            params   : $params,
            query    : $query,
            ttl      : $ttl ?? $this->defaultTtl,
            absolute : $absolute,
        );
    }
}
