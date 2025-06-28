<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends headers + body to PHP-FPM / Apache / nginx-unit, etc.
 *
 *  • Respects `header_sent()` guard
 *  • HEAD requests / 204 / 304 never emit body
 *  • Streams in 8 KiB chunks to minimise memory
 */
final class SapiEmitter implements EmitterInterface
{
    private const CHUNK = 8192;

    public function emit(ResponseInterface $resp): void
    {
        /* ---------- headers -------------------------------------- */
        if (!headers_sent()) {
            http_response_code($resp->getStatusCode());

            foreach ($resp->getHeaders() as $name => $values) {
                foreach ($values as $v) {
                    header("{$name}: {$v}", false);
                }
            }
        }

        /* ---------- body ----------------------------------------- */
        $code        = $resp->getStatusCode();
        $method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $bodyAllowed = !in_array($code, [204, 304], true) && $method !== 'HEAD';

        if (!$bodyAllowed) {
            return;
        }

        $body = $resp->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(self::CHUNK);
        }
    }
}
