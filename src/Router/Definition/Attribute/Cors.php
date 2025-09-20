<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

use Attribute;

/**
 * Defines a route-specific CORS policy.
 *
 * Use this attribute on controller methods or classes to override any global
 * CORS policy provided by middleware. The attribute is read-only and stores
 * the selected policy values which the routing/middleware layer can inspect
 * when building preflight and actual responses.
 *
 * Example:
 *   #[Cors(origins: ['https://example.com'], methods: 'GET,POST', headers: 'X-Foo')]
 *
 * @property-read string[] $origins Allowed origins (exact origin strings or '*')
 * @property-read string|null $methods Comma-separated allowed methods (e.g. "GET,POST") or null to defer
 * @property-read string|null $headers Comma-separated allowed request headers or null to defer
 * @property-read int|null $maxAgeSeconds Seconds to advertise in Access-Control-Max-Age or null to defer
 * @property-read bool|null $allowCredentials Whether to allow credentials (Access-Control-Allow-Credentials)
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Cors
{
    /**
     * Construct a route-local CORS policy descriptor.
     *
     * - $origins is a list of allowed origin values (e.g. ["https://a.example"]).
     *   Use ["*"] to allow all origins if desired (subject to middleware rules).
     * - $methods and $headers are optional comma-separated strings used to
     *   populate Access-Control-Allow-Methods and Access-Control-Allow-Headers
     *   on preflight responses. When null the global policy or middleware
     *   defaults should be used.
     * - $maxAgeSeconds controls Access-Control-Max-Age for preflight caching.
     * - $allowCredentials indicates whether credentials are permitted; when
     *   null the global/default behaviour should be preserved.
     *
     * All values are stored as public readonly properties and intended for
     * consumers (middleware/route dispatcher) to read; this attribute does not
     * itself perform any validation or header emission.
     *
     * @param string[] $origins Allowed origins (exact strings or '*')
     * @param string|null $methods Optional comma-separated allowed methods
     * @param string|null $headers Optional comma-separated allowed headers
     * @param int|null $maxAgeSeconds Optional Access-Control-Max-Age in seconds
     * @param bool|null $allowCredentials Optional flag for Access-Control-Allow-Credentials
     */
    public function __construct(
        public array $origins,
        public ?string $methods = null,
        public ?string $headers = null,
        public ?int $maxAgeSeconds = null,
        public ?bool $allowCredentials = null,
    ) {
    }
}
