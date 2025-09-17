<?php

// src/Response/Emitter/WorkermanEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class WorkermanEmitter implements EmitterInterface
{
    public function emit(Response $response, ?Request $request = null): void
    {
        // 1) Native Workerman HTTP Response object path
        $wmResp = $request?->getAttribute('workerman.response');
        if ($wmResp && method_exists($wmResp, 'withStatus')) {
            $wmResp = $wmResp->withStatus($response->getStatusCode());
            foreach ($response->getHeaders() as $n => $vals) {
                foreach ($vals as $v) {
                    $wmResp = $wmResp->withHeader($n, (string)$v);
                }
            }
            // Respect HEAD / no-body statuses
            $method = strtoupper($request?->getMethod() ?? 'GET');
            if (in_array($response->getStatusCode(), [204, 304], true) || $method === 'HEAD') {
                $wmResp->end('');
                return;
            }
            $wmResp->end((string)$response->getBody());
            return;
        }

        // 2) TcpConnection path — build raw HTTP envelope
        $conn = $request?->getAttribute('workerman.connection');
        if ($conn && method_exists($conn, 'send')) {
            $status = $response->getStatusCode() . ' ' . $response->getReasonPhrase();
            $ver = $response->getProtocolVersion();

            $method = strtoupper($request?->getMethod() ?? 'GET');
            $noBody = in_array($response->getStatusCode(), [204, 304], true) || $method === 'HEAD';

            $bodyStr = $noBody ? '' : (string)$response->getBody();

            // Ensure Content-Length present
            $headers = $response->getHeaders();
            $hasCL = array_any($headers, fn ($hv, $hn) => strtolower($hn) === 'content-length');
            if (!$hasCL) {
                $headers['Content-Length'] = [(string)\strlen($bodyStr)];
            }

            // Build envelope
            $buf = "HTTP/{$ver} {$status}\r\n";
            foreach ($headers as $n => $vals) {
                foreach ($vals as $v) {
                    $buf .= "{$n}: {$v}\r\n";
                }
            }
            $buf .= "\r\n" . $bodyStr;
            $conn->send($buf);
            return;
        }

        throw new \RuntimeException(
            'WorkermanEmitter requires "workerman.response" or "workerman.connection" attribute.',
        );
    }
}
