<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Psr\Http\Message\ResponseInterface;

/**
 * For unit tests or CLI scripts: dumps an HTTP-like envelope to STDOUT.
 */
final class CliEmitter implements EmitterInterface
{
    public function emit(ResponseInterface $resp): void
    {
        $status = $resp->getStatusCode() . ' ' . $resp->getReasonPhrase();
        $ver    = $resp->getProtocolVersion();

        echo "HTTP/{$ver} {$status}\n";
        foreach ($resp->getHeaders() as $n => $vals) {
            foreach ($vals as $v) {
                echo "{$n}: {$v}\n";
            }
        }
        echo "\n";
        echo (string) $resp->getBody();
    }
}
