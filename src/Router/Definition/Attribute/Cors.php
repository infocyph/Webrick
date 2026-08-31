<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Route-specific CORS policy validated at registration/compile time.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Cors
{
    /**
     * @param list<string> $origins
     * @param string|list<string>|null $headers
     * @param string|list<string>|null $exposeHeaders
     * @param ?string $methods
     * @param ?int $maxAgeSeconds
     * @param ?bool $allowCredentials
     * @param ?bool $allowPrivateNetwork
     */
    public function __construct(
        public array $origins,
        public ?string $methods = null,
        public string|array|null $headers = null,
        public string|array|null $exposeHeaders = null,
        public ?int $maxAgeSeconds = null,
        public ?bool $allowCredentials = null,
        public ?bool $allowPrivateNetwork = null,
    ) {
        if ($this->allowCredentials === true && in_array('*', $this->origins, true)) {
            throw new InvalidArgumentException('Credentialed CORS routes require explicit origins; wildcard origin is not allowed.');
        }
        if ($this->maxAgeSeconds !== null && $this->maxAgeSeconds < 0) {
            throw new InvalidArgumentException('CORS max age cannot be negative.');
        }
        foreach ($this->origins as $origin) {
            if (!is_string($origin) || trim($origin) === '') {
                throw new InvalidArgumentException('CORS origins must be non-empty strings.');
            }
        }
    }
}
