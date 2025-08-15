<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Sends the response to a classic PHP-SAPI (FPM, Apache, nginx-unit …).
 *
 *  • Emits headers once (guarded by headers_sent())
 *  • Never outputs a body for HEAD, 204 or 304 responses
 *  • Streams in 8 KiB chunks from PSR body
 *  • Supports live producers via Response::stream() (no Content-Length)
 *  • For HTTP/1.1 without Content-Length, emits Transfer-Encoding: chunked
 *  • Calls fastcgi_finish_request() once at the end (when available)
 */
final class SapiEmitter implements EmitterInterface
{
    private const CHUNK_SIZE = 8_192; // 8 KiB

    public function emit(Response $response, ?Request $request = null): void
    {
        $isStreaming = $response->isStreaming();
        $body = $response->getBody();
        $size = $isStreaming ? null : $body->getSize(); // producer → unknown length

        /* 1) headers ---------------------------------------------------- */
        $this->sendHeaders($response, $size, $isStreaming);

        /* 2) skip bodies for HEAD / 204 / 304 --------------------------- */
        if (!$this->shouldEmitBody($response, $request)) {
            return;
        }

        /* 3) streaming via live producer (no buffering) ----------------- */
        if ($isStreaming) {
            $this->emitFromProducer($response);
            return;
        }

        /* 4) fast-path: small in-memory streams ------------------------- */
        if ($size !== null && $size < self::CHUNK_SIZE) {
            $meta = $body->getMetadata();
            $isTemp = isset($meta['uri']) && is_string($meta['uri']) && str_starts_with($meta['uri'], 'php://temp');

            if ($isTemp && $body->isSeekable()) {
                $body->rewind();
                echo (string)$body; // single fread
                return;
            }

            if ($body->isSeekable()) {
                $body->rewind();
            }
            echo $body->getContents();
            return;
        }

        /* 5) fallback: chunked streaming from PSR body ------------------ */
        $this->emitFromBody($body);
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------*/

    private function sendHeaders(Response $response, ?int $size, bool $isStreaming): void
    {
        if (headers_sent()) {
            return;
        }

        $code = $response->getStatusCode();
        $reason = $response->getReasonPhrase();              // from Status::reason()
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

        // 1) Status line with reason phrase
        header(sprintf('%s %d %s', $protocol, $code, $reason), true, $code);

        // 2) All response headers (preserve multi-line like Set-Cookie)
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("{$name}: {$value}", false);
            }
        }

        // 3) Decide if a body will actually be sent (skip for HEAD / 204 / 304)
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $isHead = strtoupper($method) === 'HEAD';
        $allowsBody = !$isHead && !in_array($code, [204, 304], true);

        // 4) Content-Length: only when known, not streaming, and body is allowed
        if ($allowsBody && !$isStreaming && $size !== null && !$response->hasHeader('Content-Length')) {
            header("Content-Length: {$size}", false);
        }

        // 5) Transfer-Encoding: chunked (HTTP/1.1 only) when length unknown and body is allowed
        $isHttp11 = str_starts_with($protocol, 'HTTP/1.1');
        if (
            $isHttp11
            && $allowsBody
            && !$response->hasHeader('Content-Length')
            && !$response->hasHeader('Transfer-Encoding')
            && ($isStreaming || $size === null)
        ) {
            header('Transfer-Encoding: chunked', false);
        }
    }

    private function shouldEmitBody(Response $response, ?Request $request = null): bool
    {
        // Spec: no body for 204 / 304 (and never for 1xx, which you don't use here)
        if (in_array($response->getStatusCode(), [204, 304], true)) {
            return false;
        }

        // HEAD never gets a body
        $method = $request?->getMethod() ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return strtoupper($method) !== 'HEAD';
    }

    private function emitFromProducer(Response $response): void
    {
        // Reduce buffering to help streaming
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);

        $fn = $response->getProducer();
        $out = $fn ? $fn() : [];

        // If it’s an iterable, stream chunk-by-chunk
        if ($out instanceof \Generator || is_iterable($out)) {
            $isFastCgi = \function_exists('fastcgi_finish_request');
            foreach ($out as $chunk) {
                if ($chunk !== '') {
                    echo $chunk;
                }
                if (!$isFastCgi) {
                    flush();
                }
                if (function_exists('connection_aborted') && connection_aborted()) {
                    break;
                }
            }
            if ($isFastCgi) {
                @fastcgi_finish_request();
            }
            return;
        }

        // Otherwise: one-shot
        echo (string)$out;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }

    private function emitFromBody(BodyStream $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $isFastCgi = \function_exists('fastcgi_finish_request');

        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK_SIZE);
            echo $chunk;

            if (!$isFastCgi) {
                flush();
            }
        }

        if ($isFastCgi) {
            @fastcgi_finish_request();
        }
    }
}
