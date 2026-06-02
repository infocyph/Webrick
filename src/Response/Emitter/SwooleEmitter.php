<?php

// src/Response/Emitter/SwooleEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class SwooleEmitter extends BaseEmitter
{
    private ?\Swoole\Http\Response $sw = null;

    /**
     * Emits the response to the current IO target.
     * Retrieves the Swoole\Http.Response from the current request and then
     * calls the parent's emit method with the extracted response and the
     * current request.
     */
    #[\Override]
    public function emit(Response $response, ?Request $request = null): void
    {
        $this->sw = $this->extractSwooleResponse($request);
        parent::emit($response, $request);
    }

    /**
     * Clean up after emitting a response.
     * If the response was sent in chunks, ensure that the response
     * is properly terminated with no payload.
     */
    #[\Override]
    protected function finish(): void
    {
        if ($this->sw) {
            // If chunks were written via ->write(), end with no payload.
            $this->sw->end();
        }
    }

    /**
     * Swoole writes immediately when ->flush() is called.
     * Do not assume that calling ->flush() will delay the output in any way.
     */
    #[\Override]
    protected function flush(): void
    { /* Swoole writes immediately */
    }

    /**
     * Always returns false, as SwooleEmitter does not buffer headers.
     * Therefore, `headersAlreadySent()` will always return false.
     */
    #[\Override]
    protected function headersAlreadySent(): bool
    {
        return false;
    }

    /**
     * No-op. SwooleEmitter does not buffer headers, so this call has no effect.
     */
    #[\Override]
    protected function removePoweredByHeader(): void
    { /* noop */
    }

    /**
     * Sends a raw header to the output buffer.
     *
     * This should only be used by advanced users who know what they are doing.
     * Most users should use the higher-level `withHeader()` method instead.
     *
     * Swoole will not buffer the header, so this call will have immediate effect.
     */
    #[\Override]
    protected function sendRawHeader(string $name, string $value): void
    {
        $this->sw?->header($name, $value);
    }

    /**
     * SwooleEmitter returns HTTP/2 as its server protocol.
     *
     * Swoole handles HTTP/1.1 framing internally, so we don't need to
     * emit TE: chunked headers. This allows the emitter to safely filter
     * headers as if it were an HTTP/2 emitter.
     */
    #[\Override]
    protected function serverProtocol(): string
    {
        // Swoole handles framing; treat as H2 for safe header filtering
        return 'HTTP/2';
    }

    /**
     * Sets the HTTP status code of the response.
     *
     * Does not have any effect on other emitters.
     */
    #[\Override]
    protected function setStatusCode(int $code): void
    {
        $this->sw?->status($code);
    }

    /**
     * SwooleEmitter will never emit TE: chunked as it handles HTTP/1.1
     * framing internally. This method always returns false.
     */
    #[\Override]
    protected function wantsChunked(
        bool $isHttp11,
        bool $allowsBody,
        Response $response,
        bool $isStreaming,
        ?int $size,
    ): bool {
        unset($isHttp11, $allowsBody, $response, $isStreaming, $size);

        // Never emit TE: chunked — Swoole handles framing internally
        return false;
    }

    /**
     * Write a chunk of the response to the output buffer.
     *
     * If the response has been fully buffered, this call will have no effect.
     */
    #[\Override]
    protected function write(string $chunk): void
    {
        $this->sw?->write($chunk);
    }

    /**
     * Extract Swoole\Http.Response from the given Request, if available.
     *
     * @throws \RuntimeException If no Swoole\Http.Response is found in the Request.
     */
    private function extractSwooleResponse(?Request $request): \Swoole\Http\Response
    {
        $res = $request?->getAttribute('swoole.response');
        if ($res instanceof \Swoole\Http\Response) {
            return $res;
        }

        throw new \RuntimeException(
            'SwooleEmitter requires Request attribute "swoole.response" (Swoole\Http\Response).',
        );
    }
}
