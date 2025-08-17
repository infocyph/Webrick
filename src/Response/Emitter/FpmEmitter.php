<?php
// src/Response/Emitter/FpmEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Response\Response;

final class FpmEmitter extends BaseEmitter
{
    protected function finish(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }

    /**
     * FPM under HTTP/1.1: allow TE: chunked when length is unknown.
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
