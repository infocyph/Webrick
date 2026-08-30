<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

/** Request-local Swoole response emitter. */
final class SwooleEmitter extends BaseEmitter
{
    #[\Override]
    public function emit(Response $response, ?Request $request = null): void
    {
        $swoole = $this->extractSwooleResponse($request);
        $streaming = $response->isStreaming();
        $stringBody = !$streaming ? $response->getStringBody() : null;
        $size = $streaming ? null : $response->getBodySize();
        $allowsBody = $this->shouldEmitBody($response, $request);

        $swoole->status($response->getStatusCode());
        foreach ($this->filteredHeaderIterator($response, true) as [$name, $value]) {
            $swoole->header($name, $value);
        }
        if ($allowsBody && !$streaming && $size !== null && !$response->hasHeader('Content-Length')) {
            $swoole->header('Content-Length', (string) $size);
        }
        if (!$allowsBody) {
            $swoole->end();
            return;
        }
        if ($streaming) {
            $this->emitProducer($swoole, $response);
            $swoole->end();
            return;
        }
        if ($stringBody !== null) {
            $swoole->end($stringBody);
            return;
        }

        $this->emitBody($swoole, $response->getBody(), $size);
    }

    private function emitBody(\Swoole\Http\Response $swoole, BodyStream $body, ?int $size): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        if ($size !== null && $size < self::CHUNK_SIZE) {
            $swoole->end($body->getContents());
            return;
        }
        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK_SIZE);
            if ($chunk === '') {
                break;
            }
            $swoole->write($chunk);
        }
        $swoole->end();
    }

    private function emitProducer(\Swoole\Http\Response $swoole, Response $response): void
    {
        $producer = $response->getProducer();
        $output = $producer ? $producer() : [];
        if (is_iterable($output)) {
            foreach ($output as $chunk) {
                $this->writeValue($swoole, $chunk);
            }
            return;
        }
        $this->writeValue($swoole, $output);
    }

    private function extractSwooleResponse(?Request $request): \Swoole\Http\Response
    {
        $response = $request?->getAttribute('swoole.response');
        if ($response instanceof \Swoole\Http\Response) {
            return $response;
        }
        throw new RuntimeException('SwooleEmitter requires Request attribute "swoole.response".');
    }

    private function writeValue(\Swoole\Http\Response $swoole, mixed $value): void
    {
        if (is_scalar($value)) {
            $chunk = (string) $value;
            if ($chunk !== '') {
                $swoole->write($chunk);
            }
        }
    }
}
