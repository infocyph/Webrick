<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Response\Response;

interface RuntimeAdapterInterface
{
    public function capabilities(): RuntimeCapabilities;

    public function context(
        mixed $nativeRequest = null,
        mixed $nativeResponse = null,
        bool $withHost = false,
    ): RuntimeRequestContext;

    public function write(Response $response, RuntimeRequestContext $context): void;
}
