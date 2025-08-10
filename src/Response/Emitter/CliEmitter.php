<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * For unit tests or CLI scripts: dumps an HTTP-like envelope to STDOUT.
 */
final class CliEmitter implements EmitterInterface
{
    public function emit(
        Response $response,
        ?Request $request = null,
    ): void {
        $status = $response->getStatusCode() . ' ' . $response->getReasonPhrase();
        $ver = $response->getProtocolVersion();

        echo "HTTP/$ver $status\n";
        foreach ($response->getHeaders() as $n => $vals) {
            foreach ($vals as $v) {
                echo "{$n}: {$v}\n";
            }
        }
        echo "\n";
        echo (string)$response->getBody();
    }
}
