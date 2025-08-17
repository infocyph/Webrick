<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

/** NGINX Unit behaves like FastCGI from PHP’s perspective. */
final class UnitEmitter extends BaseEmitter
{
    protected function finish(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }
}
