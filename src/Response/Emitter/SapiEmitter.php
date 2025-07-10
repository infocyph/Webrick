<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Sends the response to a classic PHP-SAPI (FPM, Apache, nginx-unit …).
 *
 *  • Emits headers once (guarded by headers_sent())
 *  • Never outputs a body for HEAD, 204 or 304 responses
 *  • Streams in 8 KiB chunks – **now with a fast-path for tiny payloads**
 */
final class SapiEmitter implements EmitterInterface
{
    private const CHUNK_SIZE = 8_192;   // 8 KiB = 1 TCP segment on most stacks

    public function emit(
        ResponseInterface       $response,
        ?ServerRequestInterface $request = null,
    ): void {
        $body = $response->getBody();
        $size = $body->getSize();       // may be null (unknown/stream)

        /* 1) headers ---------------------------------------------------- */
        $this->sendHeaders($response, $size);

        /* 2) skip bodies for HEAD / 204 / 304 --------------------------- */
        if (!$this->shouldEmitBody($response, $request)) {
            return;
        }

        /* 3) fast-path for small known-size bodies (< 8 KiB) ------------ */
        if ($size !== null && $size < self::CHUNK_SIZE) {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            echo $body->getContents();          // one shot, no loop/flush
            return;
        }

        /* 4) fallback to chunked streaming ------------------------------ */
        $this->streamBody($body);
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------*/

    private function sendHeaders(ResponseInterface $response, ?int $size = null): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                // second arg=false → allow multiple Set-Cookie headers
                header("{$name}: {$value}", false);
            }
        }

        // If body size is known and caller did not set Content-Length, add it.
        if ($size !== null && !$response->hasHeader('Content-Length')) {
            header("Content-Length: {$size}", false);
        }
    }

    private function shouldEmitBody(
        ResponseInterface       $response,
        ?ServerRequestInterface $request = null,
    ): bool {
        // No body for 204/304 by spec
        if (in_array($response->getStatusCode(), [204, 304], true)) {
            return false;
        }

        // HEAD never gets a body
        $method = $request?->getMethod() ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return strtoupper($method) !== 'HEAD';
    }

    private function streamBody(StreamInterface $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);

            // FPM / nginx-unit optimisation: flush FastCGI buffer early
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                flush();
            }
        }
    }
}
