<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Attributes;

use Attribute;

/**
 * Constrain a placeholder:
 *   #[Where('id', '\d+')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class Where
{
    public function __construct(
        public string $param,
        public string $regex
    ) {}
}
