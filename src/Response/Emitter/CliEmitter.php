<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\Http\ResponseWriterSupport;

/** For unit tests or CLI scripts: dumps an HTTP-like envelope to STDOUT. */
final class CliEmitter implements EmitterInterface
{
    public function emit(Response $response, ?Request $request = null): void
    {
        $status = $response->getStatusCode() . ' ' . $response->getReasonPhrase();
        $output = 'HTTP/' . $response->getProtocolVersion() . " {$status}\n";

        foreach (ResponseWriterSupport::headers($response) as [$name, $value]) {
            $output .= "{$name}: {$value}\n";
        }
        $output .= "\n";

        $method = $request instanceof Request
            ? HttpMethodEnum::normalize($request->getMethod())
            : null;
        if ($method !== HttpMethodEnum::HEAD->value && !StatusEnum::isEmptyCode($response->getStatusCode())) {
            foreach (ResponseWriterSupport::chunks($response) as $chunk) {
                $output .= $chunk;
            }
        }

        file_put_contents('php://stdout', $output, FILE_APPEND);
    }
}
