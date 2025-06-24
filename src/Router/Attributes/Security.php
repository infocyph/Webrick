<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Declares a security requirement for this route.
 *
 * Supported schemes (so far):
 *   • bearerAuth
 *   • oauth2          (scopes array is honoured)
 *
 * Example:
 *     #[Security('bearerAuth')]
 *     #[Security('oauth2', ['admin', 'write'])]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Security
{
    /**
     * @param string        $scheme  Security scheme name
     * @param array<string> $scopes  OAuth scopes (if applicable)
     */
    public function __construct(
        public readonly string $scheme,
        public readonly array  $scopes = []
    ) {}
}
