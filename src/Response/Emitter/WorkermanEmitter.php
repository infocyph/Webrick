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
        $wmResp = $request?->getAttribute('workerman.response');
        if ($wmResp && method_exists($wmResp, 'withStatus') && $this->emitViaNativeResponse($wmResp, $response, $request)) {
            return;
        }

        $conn = $request?->getAttribute('workerman.connection');
        if ($conn && method_exists($conn, 'send') && $this->emitViaConnection($conn, $response, $request)) {
            return;
        }

        throw new \RuntimeException(
            'WorkermanEmitter requires "workerman.response" or "workerman.connection" attribute.',
        );
    }

    private function emitViaConnection(mixed $conn, Response $response, ?Request $request): bool
    {
        $bodyStr = $this->shouldEmitEmptyBody($response, $request) ? '' : (string)$response->getBody();
        $headers = $this->withContentLength($response->getHeaders(), $bodyStr);

        $status = $response->getStatusCode() . ' ' . $response->getReasonPhrase();
        $buf = "HTTP/{$response->getProtocolVersion()} {$status}\r\n";
        foreach ($headers as $n => $vals) {
            foreach ($vals as $v) {
                $buf .= "{$n}: {$v}\r\n";
            }
        }
        $buf .= "\r\n" . $bodyStr;

        $conn->send($buf);
        return true;
    }

    private function emitViaNativeResponse(mixed $wmResp, Response $response, ?Request $request): bool
    {
        $wmResp = $wmResp->withStatus($response->getStatusCode());
        foreach ($response->getHeaders() as $n => $vals) {
            foreach ($vals as $v) {
                $wmResp = $wmResp->withHeader($n, (string)$v);
            }
        }

        $body = $this->shouldEmitEmptyBody($response, $request) ? '' : (string)$response->getBody();
        $wmResp->end($body);
        return true;
    }

    private function shouldEmitEmptyBody(Response $response, ?Request $request): bool
    {
        $method = HttpMethodEnum::normalize((string)($request?->getMethod() ?? HttpMethodEnum::GET->value));
        if ($method === HttpMethodEnum::HEAD->value) {
            return true;
        }

        return \in_array(
            $response->getStatusCode(),
            [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value],
            true,
        );
    }

    /**
     * @param array<string,array<int,string>> $headers
     * @return array<string,array<int,string>>
     */
    private function withContentLength(array $headers, string $body): array
    {
        $hasCL = array_any($headers, fn ($_vals, $hn) => \strtolower((string)$hn) === 'content-length');
        if (!$hasCL) {
            $headers['Content-Length'] = [(string)\strlen($body)];
        }
        return $headers;
    }
}
