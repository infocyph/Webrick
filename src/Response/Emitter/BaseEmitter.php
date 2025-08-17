<?php

// src/Response/Emitter/BaseEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Unified, extensible HTTP emitter with SAPI-friendly defaults.
 * Subclasses can override small primitives to integrate with async servers.
 */
abstract class BaseEmitter implements EmitterInterface
{
    protected const CHUNK_SIZE = 8_192;

    /** Hop-by-hop headers forbidden on HTTP/2 */
    protected const H2_HOP_BY_HOP = [
        'connection' => true,
        'keep-alive' => true,
        'proxy-connection' => true,
        'transfer-encoding' => true,
        'upgrade' => true,
    ];

    public function emit(Response $response, ?Request $request = null): void
    {
        $isStreaming = $response->isStreaming();
        $body = $response->getBody();
        $size = $isStreaming ? null : $body->getSize();

        $this->sendHeadersCommon($response, $size, $isStreaming);

        if (!$this->shouldEmitBody($response, $request)) {
            $this->finish();
            return;
        }

        if ($isStreaming) {
            $this->emitStreaming($response);
            $this->finish();
            return;
        }

        if ($this->isSmallTempStream($body, $size)) {
            $this->emitSmall($body);
            $this->finish();
            return;
        }

        $this->emitFromBody($body);
        $this->finish();
    }

    /* -----------------------------------------------------------------
     * Headers orchestration
     * ----------------------------------------------------------------*/

    protected function sendHeadersCommon(Response $response, ?int $size, bool $isStreaming): void
    {
        if ($this->headersAlreadySent()) {
            return;
        }

        $protocol = $this->serverProtocol();
        $isHttp11 = str_starts_with($protocol, 'HTTP/1.1');
        $isHttp2 = str_starts_with($protocol, 'HTTP/2');

        $this->setStatusCode($response->getStatusCode());
        $this->removePoweredByHeader();

        foreach ($this->filteredHeaderIterator($response, $isHttp2) as [$name, $value]) {
            $this->sendRawHeader($name, $value);
        }

        $allowsBody = $this->allowsBodyForCurrentRequest($response);

        // Content-Length if known and allowed
        if (
            $allowsBody && !$isStreaming && $size !== null &&
            !$response->hasHeader('Content-Length')
        ) {
            $this->sendRawHeader('Content-Length', (string)$size);
        }

        // Transfer-Encoding: chunked — opt-in only in emitters that need it.
        if ($this->wantsChunked($isHttp11, $allowsBody, $response, $isStreaming, $size)) {
            $this->sendRawHeader('Transfer-Encoding', 'chunked');
        }
    }

    /** @return \Generator<array{0:string,1:string}> */
    protected function filteredHeaderIterator(Response $response, bool $isHttp2): \Generator
    {
        foreach ($response->getHeaders() as $name => $values) {
            $lname = strtolower($name);
            foreach ($values as $v) {
                $value = (string)$v;
                if ($this->shouldSendHeader($lname, $value, $isHttp2)) {
                    yield [$name, $value];
                }
            }
        }
    }

    protected function shouldSendHeader(string $lowerName, string $value, bool $isHttp2): bool
    {
        if (!$isHttp2) {
            return true;
        }
        if (isset(self::H2_HOP_BY_HOP[$lowerName])) {
            return false;
        }
        if ($lowerName === 'te' && strtolower(trim($value)) !== 'trailers') {
            return false;
        }
        return true;
    }

    /**
     * Default policy: DO NOT emit TE: chunked from userland.
     * Emitters that genuinely need it (e.g. classic FPM under HTTP/1.1)
     * can override and return true under safe conditions.
     */
    protected function wantsChunked(
        bool $isHttp11,
        bool $allowsBody,
        Response $response,
        bool $isStreaming,
        ?int $size,
    ): bool {
        return false;
    }

    /* -----------------------------------------------------------------
     * Body emission
     * ----------------------------------------------------------------*/

    protected function isSmallTempStream(BodyStream $body, ?int $size): bool
    {
        if ($size === null || $size >= self::CHUNK_SIZE) {
            return false;
        }
        $meta = $body->getMetadata();
        return isset($meta['uri']) && is_string($meta['uri']) && str_starts_with($meta['uri'], 'php://temp');
    }

    protected function emitSmall(BodyStream $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $this->write((string)$body);
    }

    protected function emitStreaming(Response $response): void
    {
        $this->reduceOutputBuffering();

        $fn = $response->getProducer();
        $out = $fn ? $fn() : [];

        if ($out instanceof \Generator || is_iterable($out)) {
            foreach ($out as $chunk) {
                if ($chunk !== '') {
                    $this->write($chunk);
                }
                $this->flush();
                if (\function_exists('connection_aborted') && connection_aborted()) {
                    break;
                }
            }
            return;
        }

        $this->write((string)$out);
    }

    protected function emitFromBody(BodyStream $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            $this->write($body->read(self::CHUNK_SIZE));
            $this->flush();
        }
    }

    /* -----------------------------------------------------------------
     * Policy helpers
     * ----------------------------------------------------------------*/

    protected function shouldEmitBody(Response $response, ?Request $request = null): bool
    {
        if (in_array($response->getStatusCode(), [204, 304], true)) {
            return false;
        }
        $method = $request?->getMethod() ?? ($this->serverVar('REQUEST_METHOD') ?? 'GET');
        return strtoupper($method) !== 'HEAD';
    }

    protected function allowsBodyForCurrentRequest(Response $response): bool
    {
        $code = $response->getStatusCode();
        $method = (string)($this->serverVar('REQUEST_METHOD') ?? 'GET');
        return !in_array($code, [204, 304], true) && strtoupper($method) !== 'HEAD';
    }

    /* -----------------------------------------------------------------
     * Default SAPI primitives (subclasses can override)
     * ----------------------------------------------------------------*/

    protected function headersAlreadySent(): bool
    {
        return headers_sent();
    }

    protected function setStatusCode(int $code): void
    {
        http_response_code($code);
    }

    protected function removePoweredByHeader(): void
    {
        header_remove('X-Powered-By');
    }

    protected function sendRawHeader(string $name, string $value): void
    {
        header("{$name}: {$value}", false);
    }

    protected function write(string $chunk): void
    {
        echo $chunk;
    }

    protected function flush(): void
    {
        flush();
    }

    protected function finish(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            return;
        }
        if (\function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
            return;
        }
        if (\function_exists('frankenphp_finish_request')) {
            @frankenphp_finish_request();
            return;
        }
    }

    protected function serverProtocol(): string
    {
        return (string)($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
    }

    protected function serverVar(string $name): mixed
    {
        return $_SERVER[$name] ?? null;
    }

    /** For buffered SAPIs */
    protected function reduceOutputBuffering(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);
    }
}
