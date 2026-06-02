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
    /**
     * Dumps an HTTP-like envelope to STDOUT.
     */
    public function emit(
        Response $response,
        ?Request $request = null,
    ): void {
        unset($request);

        $status = $response->getStatusCode() . ' ' . $response->getReasonPhrase();
        $ver = $response->getProtocolVersion();

        file_put_contents('php://stdout', "HTTP/$ver $status\n", FILE_APPEND);
        foreach ($response->getHeaders() as $n => $vals) {
            foreach ($vals as $v) {
                file_put_contents('php://stdout', "{$n}: {$v}\n", FILE_APPEND);
            }
        }
        file_put_contents('php://stdout', "\n", FILE_APPEND);
        file_put_contents('php://stdout', (string) $response->getBody(), FILE_APPEND);
    }
}
