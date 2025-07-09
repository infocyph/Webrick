<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Sends the response to a classic PHP-SAPI (FPM, Apache, nginx-unit …).
 *
 *  • Emits headers once (guarded by headers_sent())
 *  • Never outputs a body for HEAD, 204 or 304 responses
 *  • Streams in 8 KiB chunks and flushes after each iteration
 */
final class SapiEmitter implements EmitterInterface
{
    private const CHUNK_SIZE = 8192;

    public function emit(
        ResponseInterface        $response,
        ?ServerRequestInterface  $request = null,
    ): void {
        $this->sendHeaders($response);

        if (! $this->shouldEmitBody($response, $request)) {
            return; //  HEAD / 204 / 304
        }

        $this->streamBody($response);
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------*/

    private function sendHeaders(ResponseInterface $response): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                /** second arg = false → allow multiple Set-Cookie headers */
                header("{$name}: {$value}", false);
            }
        }
    }

    private function shouldEmitBody(
        ResponseInterface        $response,
        ?ServerRequestInterface  $request = null,
    ): bool {
        $status = $response->getStatusCode();
        if (in_array($status, [204, 304], true)) {
            return false;
        }

        $method = $request?->getMethod()            // preferred – no globals
            ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'); // fallback for BC

        return $method !== 'HEAD';
    }

    private function streamBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (! $body->eof()) {
            echo $body->read(self::CHUNK_SIZE);

            // FPM / nginx-unit optimisation: flush the FastCGI buffer early
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                flush();
            }
        }
    }
}
