<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

/**
 * Request-local Swoole response emitter.
 *
 * The native Swoole response is never stored on the emitter instance, so one
 * emitter may safely be reused across concurrent coroutines.
 */
final class SwooleEmitter extends BaseEmitter
{
    #[\Override]
    public function emit(Response $response, ?Request $request = null): void
    {
        $sw = $this->extractSwooleResponse($request);
        $isStreaming = $response->isStreaming();
        $body = $response->getBody();
        $size = $isStreaming ? null : $body->getSize();
        $allowsBody = $this->shouldEmitBody($response, $request);

        $sw->status($response->getStatusCode());
        foreach ($this->filteredHeaderIterator($response, true) as [$name, $value]) {
            $sw->header($name, $value);
        }

        if ($allowsBody && !$isStreaming && $size !== null && !$response->hasHeader('Content-Length')) {
            $sw->header('Content-Length', (string) $size);
        }

        if (!$allowsBody) {
            $sw->end();

            return;
        }

        if ($isStreaming) {
            $this->emitProducer($sw, $response);
            $sw->end();

            return;
        }

        $this->emitBody($sw, $body, $size);
    }

    private function emitBody(\Swoole\Http\Response $sw, BodyStream $body, ?int $size): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($size !== null && $size < self::CHUNK_SIZE) {
            $sw->end($body->getContents());

            return;
        }

        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK_SIZE);
            if ($chunk === '') {
                break;
            }
            $sw->write($chunk);
        }
        $sw->end();
    }

    private function emitProducer(\Swoole\Http\Response $sw, Response $response): void
    {
        $producer = $response->getProducer();
        $out = $producer ? $producer() : [];

        if (is_iterable($out)) {
            foreach ($out as $chunk) {
                $this->writeValue($sw, $chunk);
            }

            return;
        }

        $this->writeValue($sw, $out);
    }

    private function extractSwooleResponse(?Request $request): \Swoole\Http\Response
    {
        $response = $request?->getAttribute('swoole.response');
        if ($response instanceof \Swoole\Http\Response) {
            return $response;
        }

        throw new RuntimeException(
            'SwooleEmitter requires Request attribute "swoole.response" (Swoole\\Http\\Response).',
        );
    }

    private function writeValue(\Swoole\Http\Response $sw, mixed $value): void
    {
        if (is_string($value)) {
            if ($value !== '') {
                $sw->write($value);
            }

            return;
        }

        if (is_scalar($value)) {
            $value = (string) $value;
            if ($value !== '') {
                $sw->write($value);
            }
        }
    }
}
