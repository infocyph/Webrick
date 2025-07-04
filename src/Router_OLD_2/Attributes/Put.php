<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router_OLD\Attributes;

use Attribute;

/** #[Put('/uri')] */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class Put
{
    public function __construct(public string $path) {}
}
