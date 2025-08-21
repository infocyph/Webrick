<?php

// src/Router/Definition/Attribute/Produces.php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class Produces
{
    /** @param string[] $types @param string[]|null $charsets */
    public function __construct(
        public array $types,
        public ?array $charsets = null,
    ) {
    }
}
