<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Psr\Http\Message\ResponseInterface;

/** Very small contract: emit the response to the current IO target. */
interface EmitterInterface
{
    public function emit(ResponseInterface $response): void;
}
