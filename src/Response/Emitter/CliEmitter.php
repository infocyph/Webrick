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
     * @param Response $response
     * @param ?Request $request
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed -- Required by EmitterInterface.
    public function emit(
        Response $response,
        ?Request $request = null,
    ): void {
        $status = $response->getStatusCode() . ' ' . $response->getReasonPhrase();
        $ver = $response->getProtocolVersion();

        $output = "HTTP/$ver $status\n";
        foreach ($response->getHeaders() as $n => $vals) {
            foreach ($vals as $v) {
                $output .= "{$n}: {$v}\n";
            }
        }
        $output .= "\n" . $response->getBody();
        file_put_contents('php://stdout', $output, FILE_APPEND);
    }
}
