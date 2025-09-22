<?php

// src/Response/Emitter/FpmEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Response\Response;

final class FpmEmitter extends BaseEmitter
{
    /**
     * For FPM under HTTP/1.1, this is a no-op.
     * For other SAPIs, this is the last chance to flush any data.
     */
    protected function finish(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }


    /**
     * Determine if the emitter wants to send the response body chunked.
     *
     * FPM under HTTP/1.1 will send chunked responses when:
     *   - The response has a body
     *   - The response does not have a `Content-Length` header
     *   - The response does not have a `Transfer-Encoding` header
     *   - The response is streaming (i.e. it has no known size)
     *
     * For other SAPIs, this method will always return false, as they do not support chunked responses.
     *
     * @return bool true if the emitter wants to send the response body chunked, false otherwise.
     */
    protected function wantsChunked(
        bool $isHttp11,
        bool $allowsBody,
        Response $response,
        bool $isStreaming,
        ?int $size,
    ): bool {
        return $isHttp11
            && $allowsBody
            && !$response->hasHeader('Content-Length')
            && !$response->hasHeader('Transfer-Encoding')
            && ($isStreaming || $size === null);
    }
}
