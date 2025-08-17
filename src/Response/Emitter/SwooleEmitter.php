<?php

// src/Response/Emitter/SwooleEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class SwooleEmitter extends BaseEmitter
{
    private ?\Swoole\Http\Response $sw = null;
    private bool $wrote = false;

    public function emit(Response $response, ?Request $request = null): void
    {
        $this->sw = $this->extractSwooleResponse($request);
        parent::emit($response, $request);
    }

    /* ---- override primitives ---- */

    protected function headersAlreadySent(): bool
    {
        return false;
    }

    protected function setStatusCode(int $code): void
    {
        $this->sw?->status($code);
    }

    protected function removePoweredByHeader(): void
    { /* noop */
    }

    protected function sendRawHeader(string $name, string $value): void
    {
        $this->sw?->header($name, $value);
    }

    protected function write(string $chunk): void
    {
        $this->wrote = true;
        $this->sw?->write($chunk);
    }

    protected function flush(): void
    { /* Swoole writes immediately */
    }

    protected function finish(): void
    {
        if ($this->sw && method_exists($this->sw, 'end')) {
            // If chunks were written via ->write(), end with no payload.
            $this->sw->end();
        }
    }

    protected function serverProtocol(): string
    {
        // Swoole handles framing; treat as H2 for safe header filtering
        return 'HTTP/2';
    }

    protected function wantsChunked(bool $isHttp11, bool $allowsBody, Response $response, bool $isStreaming, ?int $size): bool
    {
        // Never emit TE: chunked — Swoole handles framing internally
        return false;
    }

    private function extractSwooleResponse(?Request $request): \Swoole\Http\Response
    {
        $res = $request?->getAttribute('swoole.response');
        if ($res instanceof \Swoole\Http\Response) {
            return $res;
        }
        throw new \RuntimeException('SwooleEmitter requires Request attribute "swoole.response" (Swoole\Http\Response).');
    }
}
