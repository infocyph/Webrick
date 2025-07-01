<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router_OLD\Attributes;

use Attribute;

/** #[Name('users.show')] */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class Name
{
    public function __construct(public string $value) {}
}
