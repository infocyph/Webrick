<?php
// src/Response/Emitter/FrankenPhpEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

final class FrankenPhpEmitter extends BaseEmitter
{
    protected function finish(): void
    {
        if (\function_exists('frankenphp_finish_request')) {
            @frankenphp_finish_request();
        }
    }
}
