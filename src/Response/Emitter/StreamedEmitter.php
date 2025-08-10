<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Payloads\StreamedResponse as PayloadStreamedResponse;

/**
 * Always streams the body in chunks. If the Response is a StreamedResponse with a
 * producer, this emitter will stream directly from the callable/generator (no buffering)
 * and will avoid auto-adding Content-Length.
 */
final readonly class StreamedEmitter implements EmitterInterface
{
    public function __construct(private int $chunk = 8192) {}

    public function emit(Response $response, ?Request $request = null): void
    {
        $producer = $this->producerFor($response);

        $this->sendHeaders($response, $producer);

        if ($this->isHeadOrNoBody($response, $request)) {
            return;
        }

        if ($producer !== null) {
            $this->emitFromProducer($producer);
            return;
        }

        $this->emitFromBody($response->getBody());
    }

    /* ───────────────────────── internals ───────────────────────── */

    private function producerFor(Response $response): ?callable
    {
        return $response instanceof PayloadStreamedResponse
            ? $response->getProducer()
            : null;
    }

    private function sendHeaders(Response $response, ?callable $producer): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                // second arg = false → allow multi-line headers (e.g., Set-Cookie)
                header("{$name}: {$value}", false);
            }
        }

        // Only auto Content-Length when we're NOT using a live producer
        if ($producer === null) {
            $size = $response->getBody()->getSize();
            if ($size !== null && !$response->hasHeader('Content-Length')) {
                header("Content-Length: {$size}", false);
            }
        }
    }

    private function isHeadOrNoBody(Response $response, ?Request $request): bool
    {
        if (\in_array($response->getStatusCode(), [204, 304], true)) {
            return true;
        }
        return strtoupper($this->method($request)) === 'HEAD';
    }

    private function method(?Request $request): string
    {
        return $request?->getMethod() ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    private function emitFromProducer(callable $producer): void
    {
        $out = $producer();
        if ($out instanceof \Generator) {
            foreach ($out as $chunk) {
                echo $chunk;
                flush();
            }
            return;
        }

        echo (string)$out;
        flush();
    }

    private function emitFromBody(BodyStream $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read($this->chunk);
            flush();
        }
    }
}
