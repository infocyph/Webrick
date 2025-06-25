<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Rate-limits a route.
 *
 * Modes:
 *   • Fixed window  – default  (algo:'fixed')
 *   • Token bucket  – smooth burst (algo:'token')
 *
 * Key identifiers:
 *   • 'ip'                  – client IP (default)
 *   • 'header:X-API-Key'    – custom header value
 *   • 'jwt.sub'             – “sub” claim from Bearer JWT
 *
 * Example:
 *     #[Throttle(60, 60)]                               // 60 req/min per IP
 *     #[Throttle(1000, 86400, key:'header:X-API-Key')]  // 1 000/day per key
 *     #[Throttle(120, 60, key:'jwt.sub', algo:'token')] // token-bucket per user
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Throttle
{
    public function __construct(
        public readonly int    $limit,
        public readonly int    $seconds,
        public readonly string $key  = 'ip',
        public readonly string $algo = 'fixed',   // 'fixed' | 'token'
    ) {
    }
}
