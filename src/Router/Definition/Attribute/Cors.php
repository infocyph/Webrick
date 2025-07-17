<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

use Attribute;

/**
 * Defines a route-specific CORS policy.
 * Overrides any globally configured CorsMiddleware policy.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Cors
{
    /**
     * @param string[] $origins
     * @param string|null $methods
     * @param string|null $headers
     * @param int|null $maxAgeSeconds
     * @param bool|null $allowCredentials
     */
    public function __construct(
        public array   $origins,
        public ?string  $methods        = null,
        public ?string  $headers        = null,
        public ?int    $maxAgeSeconds  = null,
        public ?bool    $allowCredentials = null,
    ) {
    }
}
