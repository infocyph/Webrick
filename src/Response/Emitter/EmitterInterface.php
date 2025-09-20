<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/** Emit the response to the current IO target. */
interface EmitterInterface
{
    public function emit(Response $response, ?Request $request = null): void;
}
