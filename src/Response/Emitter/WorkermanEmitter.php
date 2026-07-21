<?php

// src/Response/Emitter/WorkermanEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Response\Response;

final class WorkermanEmitter implements EmitterInterface
{
    /** @var array<class-string, \ReflectionMethod> */
    private static array $endMethods = [];

    /**
     * Emit the response to the current IO target.
     * Supports two native Workerman HTTP Response object paths:
     * 1. Native Workerman HTTP Response object path
     * 2. TcpConnection path — build raw HTTP envelope
     *
     * @throws \RuntimeException
     */
    public function emit(Response $response, ?Request $request = null): void
    {
        if ($request === null) {
            throw new \RuntimeException('WorkermanEmitter requires a Request instance.');
        }

        $wmResp = $request->getAttribute('workerman.response');
        if (is_object($wmResp) && method_exists($wmResp, 'withStatus') && $this->emitViaNativeResponse($wmResp, $response, $request)) {
            return;
        }

        $conn = $request->getAttribute('workerman.connection');
        if (is_object($conn) && method_exists($conn, 'send') && $this->emitViaConnection($conn, $response, $request)) {
            return;
        }

        throw new \RuntimeException(
            'WorkermanEmitter requires "workerman.response" or "workerman.connection" attribute.',
        );
    }

    private function emitViaConnection(object $conn, Response $response, ?Request $request): bool
    {
        if (!is_callable([$conn, 'send'])) {
            return false;
        }

        $bodyStr = $this->shouldEmitEmptyBody($response, $request) ? '' : (string) $response->getBody();
        $headers = $this->withContentLength($this->normalizeHeaders($response->getHeaders()), $bodyStr);

        $status = $response->getStatusCode() . ' ' . $response->getReasonPhrase();
        $buf = "HTTP/{$response->getProtocolVersion()} {$status}\r\n";
        foreach ($headers as $n => $vals) {
            foreach ($vals as $v) {
                $buf .= "{$n}: {$v}\r\n";
            }
        }
        $buf .= "\r\n" . $bodyStr;

        call_user_func([$conn, 'send'], $buf);

        return true;
    }

    private function emitViaNativeResponse(object $wmResp, Response $response, ?Request $request): bool
    {
        if (!is_callable([$wmResp, 'withStatus']) || !is_callable([$wmResp, 'end'])) {
            return false;
        }

        $next = call_user_func([$wmResp, 'withStatus'], $response->getStatusCode());
        if (!is_object($next)) {
            return false;
        }
        $wmResp = $next;
        foreach ($this->normalizeHeaders($response->getHeaders()) as $n => $vals) {
            foreach ($vals as $v) {
                if (!is_callable([$wmResp, 'withHeader'])) {
                    return false;
                }

                $next = call_user_func([$wmResp, 'withHeader'], $n, $v);
                if (!is_object($next)) {
                    return false;
                }
                $wmResp = $next;
            }
        }

        $body = $this->shouldEmitEmptyBody($response, $request) ? '' : (string) $response->getBody();
        $class = $wmResp::class;
        (self::$endMethods[$class] ??= new \ReflectionMethod($wmResp, 'end'))->invoke($wmResp, $body);

        return true;
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, array<int, string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        return Utils::normalizeHeaderValueLists($headers);
    }

    private function shouldEmitEmptyBody(Response $response, ?Request $request): bool
    {
        $method = HttpMethodEnum::normalize((string) ($request?->getMethod() ?? HttpMethodEnum::GET->value));
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
        $hasCL = array_any(
            $headers,
            static fn(array $vals, string|int $hn): bool => $vals !== [] && \strtolower((string) $hn) === 'content-length',
        );
        if (!$hasCL) {
            $headers['Content-Length'] = [(string) \strlen($body)];
        }

        return $headers;
    }
}
