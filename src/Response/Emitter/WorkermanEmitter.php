<?php

// src/Response/Emitter/WorkermanEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class WorkermanEmitter implements EmitterInterface
{
    /**
     * Emit the response to the current IO target.
     * Supports two native Workerman HTTP Response object paths:
     * 1. Native Workerman HTTP Response object path
     * 2. TcpConnection path — build raw HTTP envelope
     *
     * @param Response $response
     * @param Request|null $request
     * @throws \RuntimeException
     */
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
            $method = HttpMethodEnum::normalize((string)($request?->getMethod() ?? HttpMethodEnum::GET->value));
            if (
                in_array($response->getStatusCode(), [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value], true)
                || $method === HttpMethodEnum::HEAD->value
            ) {
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

            $method = HttpMethodEnum::normalize((string)($request?->getMethod() ?? HttpMethodEnum::GET->value));
            $noBody = in_array($response->getStatusCode(), [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value], true)
                || $method === HttpMethodEnum::HEAD->value;

            $bodyStr = $noBody ? '' : (string)$response->getBody();

            // Ensure Content-Length present
            $headers = $response->getHeaders();
            $hasCL = array_any($headers, fn ($_vals, $hn) => strtolower((string)$hn) === 'content-length');
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
