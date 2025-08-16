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
 *  • Filters hop-by-hop headers on HTTP/2 to avoid PROTOCOL_ERROR
 *  • Calls fastcgi_finish_request() once at the end (when available)
 */
final class SapiEmitter implements EmitterInterface
{
    private const CHUNK_SIZE = 8_192; // 8 KiB

    /** Hop-by-hop headers forbidden on HTTP/2 */
    private const H2_HOP_BY_HOP = [
        'connection'         => true,
        'keep-alive'         => true,
        'proxy-connection'   => true,
        'transfer-encoding'  => true,
        'upgrade'            => true,
    ];

    public function emit(Response $response, ?Request $request = null): void
    {
        $isStreaming = $response->isStreaming();
        $body        = $response->getBody();
        $size        = $isStreaming ? null : $body->getSize(); // producer → unknown length

        $this->sendHeaders($response, $size, $isStreaming);

        if (!$this->shouldEmitBody($response, $request)) {
            return;
        }

        $this->emitBody($response, $body, $size, $isStreaming);
    }

    /* -----------------------------------------------------------------
     * Header emission
     * ----------------------------------------------------------------*/

    private function sendHeaders(Response $response, ?int $size, bool $isStreaming): void
    {
        if (headers_sent()) {
            return;
        }

        $protocol = (string)($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
        $isHttp11 = $this->isHttp11($protocol);
        $isHttp2  = $this->isHttp2($protocol);

        $this->emitStatus($response->getStatusCode());
        $this->sanitizeSapiDefaults();

        $this->emitHeaderLines($response, $isHttp2);

        $this->finalizeLengthAndEncoding(
            $response,
            $size,
            $isStreaming,
            $isHttp11,
            $this->allowsBodyForCurrentRequest($response)
        );
    }

    private function emitStatus(int $code): void
    {
        // Let SAPI set the status; compatible with HTTP/1.x and HTTP/2.
        http_response_code($code);
    }

    private function sanitizeSapiDefaults(): void
    {
        // Remove X-Powered-By if enabled in php.ini
        header_remove('X-Powered-By');
    }

    private function emitHeaderLines(Response $response, bool $isHttp2): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            $lname = strtolower($name);

            foreach ($values as $value) {
                if ($isHttp2) {
                    // Drop hop-by-hop headers on HTTP/2
                    if (isset(self::H2_HOP_BY_HOP[$lname])) {
                        continue;
                    }
                    // TE is only allowed with the value "trailers"
                    if ($lname === 'te' && strtolower(trim((string)$value)) !== 'trailers') {
                        continue;
                    }
                }
                header("{$name}: {$value}", false);
            }
        }
    }

    private function finalizeLengthAndEncoding(
        Response $response,
        ?int $size,
        bool $isStreaming,
        bool $isHttp11,
        bool $allowsBody
    ): void {
        // Only set Content-Length if we will send a body and length is known
        if (
            $allowsBody
            && !$isStreaming
            && $size !== null
            && !$response->hasHeader('Content-Length')
        ) {
            header("Content-Length: {$size}", false);
        }

        // Emit Transfer-Encoding: chunked only for HTTP/1.1 with unknown length
        if (
            $isHttp11
            && $allowsBody
            && !$response->hasHeader('Content-Length')
            && !$response->hasHeader('Transfer-Encoding')
            && ($isStreaming || $size === null)
        ) {
            header('Transfer-Encoding: chunked', false);
        }
        // NOTE: Never emit Transfer-Encoding on HTTP/2 – server handles framing.
    }

    /* -----------------------------------------------------------------
     * Body emission
     * ----------------------------------------------------------------*/

    private function emitBody(Response $response, BodyStream $body, ?int $size, bool $isStreaming): void
    {
        if ($isStreaming) {
            $this->emitFromProducer($response);
            return;
        }

        if ($this->isSmallInMemory($body, $size)) {
            $this->emitSmallBody($body);
            return;
        }

        $this->emitFromBody($body);
    }

    private function isSmallInMemory(BodyStream $body, ?int $size): bool
    {
        if ($size === null || $size >= self::CHUNK_SIZE) {
            return false;
        }
        $meta  = $body->getMetadata();
        return isset($meta['uri']) && is_string($meta['uri']) && str_starts_with($meta['uri'], 'php://temp');
    }

    private function emitSmallBody(BodyStream $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        echo (string)$body; // single fread for temp streams
    }

    private function emitFromProducer(Response $response): void
    {
        $this->reduceOutputBuffering();

        $fn  = $response->getProducer();
        $out = $fn ? $fn() : [];

        if ($out instanceof \Generator || is_iterable($out)) {
            $this->streamIterable($out);
            return;
        }

        echo (string)$out;
        $this->finishFastCgi();
    }

    private function emitFromBody(BodyStream $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $isFastCgi = \function_exists('fastcgi_finish_request');

        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);
            if (!$isFastCgi) {
                flush();
            }
        }

        $this->finishFastCgi();
    }

    /* -----------------------------------------------------------------
     * Control helpers
     * ----------------------------------------------------------------*/

    private function shouldEmitBody(Response $response, ?Request $request = null): bool
    {
        // Spec: no body for 204 / 304 (and never for 1xx)
        if (in_array($response->getStatusCode(), [204, 304], true)) {
            return false;
        }
        // HEAD never gets a body
        $method = $request?->getMethod() ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return strtoupper($method) !== 'HEAD';
    }

    private function allowsBodyForCurrentRequest(Response $response): bool
    {
        // Mirror shouldEmitBody logic but without needing the Request
        $code = $response->getStatusCode();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        return !in_array($code, [204, 304], true) && strtoupper($method) !== 'HEAD';
    }

    private function isHttp11(string $protocol): bool
    {
        return str_starts_with($protocol, 'HTTP/1.1');
    }

    private function isHttp2(string $protocol): bool
    {
        return str_starts_with($protocol, 'HTTP/2');
    }

    private function reduceOutputBuffering(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);
    }

    private function streamIterable(iterable $chunks): void
    {
        $isFastCgi = \function_exists('fastcgi_finish_request');
        foreach ($chunks as $chunk) {
            if ($chunk !== '') {
                echo $chunk;
            }
            if (!$isFastCgi) {
                flush();
            }
            if (\function_exists('connection_aborted') && connection_aborted()) {
                break;
            }
        }
        $this->finishFastCgi();
    }

    private function finishFastCgi(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }
}
