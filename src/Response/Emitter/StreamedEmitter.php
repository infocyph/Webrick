<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Same semantics as `SapiEmitter`, but *always* streams the body in chunks –
 * useful for large downloads or SSE where you want flush() after each read.
 */
final readonly class StreamedEmitter implements EmitterInterface
{
    public function __construct(private int $chunk = 8192)
    {
    }

    public function emit(Response $response, ?Request $request = null): void
    {
        $sapi = new SapiEmitter();
        $sapi->emit($response, $request);          // still sends headers

        // ── NO body for HEAD / 204 / 304 ───────────────────────────────
        $method = $request?->getMethod() ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (\in_array($response->getStatusCode(), [204, 304], true) ||
            strtoupper($method) === 'HEAD') {
            return;
        }

        // ── chunked streaming ──────────────────────────────────────────
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read($this->chunk);
            flush();
        }
    }
}
