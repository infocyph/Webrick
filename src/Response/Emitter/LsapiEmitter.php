<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

final class LsapiEmitter extends BaseEmitter
{
/**
 * Litespeed finish request handler.
 * Called after sending the response to ensure compatibility with Litespeed.
 */
    protected function finish(): void
    {
        if (\function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
    }
}
