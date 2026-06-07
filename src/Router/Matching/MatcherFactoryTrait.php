<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

trait MatcherFactoryTrait
{
    private function __construct() {}

    public static function make(): static
    {
        return new static();
    }
}
