<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Compile-time route response media metadata.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Produces
{
    /**
     * @param list<string> $types
     * @param list<string>|null $charsets
     */
    public function __construct(
        public array $types,
        public ?array $charsets = null,
    ) {
        if ($this->types === []) {
            throw new InvalidArgumentException('Produces must declare at least one media type.');
        }
        foreach ($this->types as $type) {
            if (trim($type) === '') {
                throw new InvalidArgumentException('Produces media types must be non-empty strings.');
            }
        }
        foreach ($this->charsets ?? [] as $charset) {
            if (trim($charset) === '') {
                throw new InvalidArgumentException('Produces charsets must be non-empty strings.');
            }
        }
    }
}
