<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;


use Infocyph\Webrick\Response\Response;

/** Very small contract: emit the response to the current IO target. */
interface EmitterInterface
{
    public function emit(Response $response): void;
}
