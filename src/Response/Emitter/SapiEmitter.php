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
 *  • Streams in 8 KiB chunks – with a fast-path for tiny payloads
 *  • **fastcgi_finish_request()** is now called exactly once, _after_ the loop
 *    to avoid an extra function-call per chunk while still flushing promptly.
 */
final class SapiEmitter implements EmitterInterface
{
    private const CHUNK_SIZE = 8_192; // 8 KiB ≈ one TCP segment

    public function emit(
        ResponseInterface       $response,
        ?ServerRequestInterface $request = null,
    ): void {
        $body = $response->getBody();
        $size = $body->getSize(); // may be null (unknown stream)

        /* 1) headers ---------------------------------------------------- */
        $this->sendHeaders($response, $size);

        /* 2) skip bodies for HEAD / 204 / 304 --------------------------- */
        if (!$this->shouldEmitBody($response, $request)) {
            return;
        }

        /* 3) fast-path: small in-memory streams ------------------------- */
        if ($size !== null && $size < self::CHUNK_SIZE) {
            $meta = $body->getMetadata();
            $isTemp = isset($meta['uri']) && str_starts_with($meta['uri'], 'php://temp');

            if ($isTemp && $body->isSeekable()) {
                $body->rewind();
                echo (string) $body;    // to-string → single fread
                return;
            }

            if ($body->isSeekable()) {
                $body->rewind();
            }
            echo $body->getContents();
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
        static $sent = false;
        if ($sent || headers_sent()) {
            return;
        }

        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                // second arg = false → allow multiple Set-Cookie headers
                header("{$name}: {$value}", false);
            }
        }

        // Auto-add Content-Length when size known and not set by caller.
        if ($size !== null && !$response->hasHeader('Content-Length')) {
            header("Content-Length: {$size}", false);
        }
        $sent = true;
    }

    private function shouldEmitBody(
        ResponseInterface       $response,
        ?ServerRequestInterface $request = null,
    ): bool {
        // Spec: no body for 204 / 304
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

        $isFastCgi = \function_exists('fastcgi_finish_request');

        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);

            // For non-FPM SAPIs flush output after each chunk.
            if (!$isFastCgi) {
                flush();
            }
        }

        // One single FastCGI flush at the end (avoids per-chunk overhead).
        if ($isFastCgi) {
            @fastcgi_finish_request();
        }
    }
}
