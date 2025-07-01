<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router_OLD\Attributes;

use Attribute;

/** #[Head('/uri')] */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class Head
{
    public function __construct(public string $path) {}
}
