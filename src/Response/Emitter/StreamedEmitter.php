<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Psr\Http\Message\ResponseInterface;

/**
 * Same semantics as `SapiEmitter`, but *always* streams the body in chunks –
 * useful for large downloads or SSE where you want flush() after each read.
 */
final class StreamedEmitter implements EmitterInterface
{
    public function __construct(private int $chunk = 8192) {}

    public function emit(ResponseInterface $resp): void
    {
        (new SapiEmitter())->emit($resp); // headers + basic guards

        $body = $resp->getBody();
        if ($body->isSeekable()) { $body->rewind(); }

        while (!$body->eof()) {
            echo $body->read($this->chunk);
            flush();
        }
    }
}
