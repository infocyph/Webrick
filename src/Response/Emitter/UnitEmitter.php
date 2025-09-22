<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

/** NGINX Unit behaves like FastCGI from PHP’s perspective. */
final class UnitEmitter extends BaseEmitter
{
    /**
     * FastCGI finish request handler.
     * Called after sending the response to ensure compatibility with FastCGI.
     */
    protected function finish(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }
}
