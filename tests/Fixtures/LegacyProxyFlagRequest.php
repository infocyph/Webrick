<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Fixture;

use Infocyph\Webrick\Request\Request;

final class LegacyProxyFlagRequest extends Request
{
    public const HEADER_FORWARDED = 16;

    public const HEADER_X_FORWARDED_FOR = 1;

    public const HEADER_X_FORWARDED_HOST = 2;

    public const HEADER_X_FORWARDED_PORT = 8;

    public const HEADER_X_FORWARDED_PROTO = 4;
}
