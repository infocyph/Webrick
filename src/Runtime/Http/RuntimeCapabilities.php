<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

/** Immutable transport/runtime capabilities resolved once at bootstrap. */
final readonly class RuntimeCapabilities
{
    public function __construct(
        public string $name,
        public bool $persistent = false,
        public bool $concurrent = false,
        public bool $nativeStreaming = false,
        public bool $nativeFile = false,
        public bool $transportCompression = false,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Runtime capability name must be non-empty.');
        }
    }
}
