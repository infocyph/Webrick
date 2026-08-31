<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/** Unified, extensible HTTP emitter with SAPI-friendly defaults. */
abstract class BaseEmitter implements EmitterInterface
{
    protected const CHUNK_SIZE = 8_192;

    protected const H2_HOP_BY_HOP = [
        'connection' => true,
        'keep-alive' => true,
        'proxy-connection' => true,
        'transfer-encoding' => true,
        'upgrade' => true,
    ];

    /** @var resource|null */
    private mixed $outputStream = null;

    public function emit(Response $response, ?Request $request = null): void
    {
        $isStreaming = $response->isStreaming();
        $body = $response->getBody();
        $size = $isStreaming ? null : $body->getSize();
        $allowsBody = $this->shouldEmitBody($response, $request);

        $this->sendHeadersCommon($response, $size, $isStreaming, $allowsBody);
        if (!$allowsBody) {
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

    protected function allowsBodyForCurrentRequest(Response $response): bool
    {
        $method = HttpMethodEnum::normalize($this->serverString('REQUEST_METHOD', HttpMethodEnum::GET->value));

        return !self::statusHasNoContent($response->getStatusCode())
            && $method !== HttpMethodEnum::HEAD->value;
    }

    protected function emitFromBody(BodyStream $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK_SIZE);
            if ($chunk === '') {
                break;
            }
            $this->write($chunk);
            $this->flush();
        }
    }

    protected function emitSmall(BodyStream $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $this->write((string) $body);
    }

    protected function emitStreaming(Response $response): void
    {
        $this->reduceOutputBuffering();
        $fn = $response->getProducer();
        $out = $fn ? $fn() : [];
        $this->emitIterableOutput($out);
    }

    /** @return \Generator<array{0:string,1:string}> */
    protected function filteredHeaderIterator(Response $response, bool $isHttp2): \Generator
    {
        foreach ($response->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            if ($lower === 'content-length' && self::forbidsContentLength($response->getStatusCode())) {
                continue;
            }
            foreach ($values as $value) {
                if ($this->shouldSendHeader($lower, $value, $isHttp2)) {
                    yield [$name, $value];
                }
            }
        }
    }

    protected function finish(): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }
        if (\function_exists('litespeed_finish_request')) {
            litespeed_finish_request();

            return;
        }
        if (\function_exists('frankenphp_finish_request')) {
            frankenphp_finish_request();
        }
    }

    protected function flush(): void
    {
        flush();
    }

    protected function headersAlreadySent(): bool
    {
        return headers_sent();
    }

    protected function isSmallTempStream(BodyStream $body, ?int $size): bool
    {
        if ($size === null || $size >= self::CHUNK_SIZE) {
            return false;
        }
        $meta = $body->getMetadata();
        if (!\is_array($meta)) {
            return false;
        }
        $uri = $meta['uri'] ?? null;

        return \is_string($uri) && str_starts_with($uri, 'php://temp');
    }

    protected function reduceOutputBuffering(): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
    }

    protected function removePoweredByHeader(): void
    {
        header_remove('X-Powered-By');
    }

    protected function sendHeadersCommon(
        Response $response,
        ?int $size,
        bool $isStreaming,
        ?bool $allowsBody = null,
    ): void {
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

        $allowsBody ??= $this->allowsBodyForCurrentRequest($response);
        if ($allowsBody && !$isStreaming && $size !== null && !$response->hasHeader('Content-Length')) {
            $this->sendRawHeader('Content-Length', (string) $size);
        }
        if ($this->wantsChunked($isHttp11, $allowsBody, $response, $isStreaming, $size)) {
            $this->sendRawHeader('Transfer-Encoding', 'chunked');
        }
    }

    protected function sendRawHeader(string $name, string $value): void
    {
        header("{$name}: {$value}", false);
    }

    protected function serverProtocol(): string
    {
        return $this->serverString('SERVER_PROTOCOL', 'HTTP/1.1');
    }

    protected function serverString(string $name, string $default = ''): string
    {
        $value = $this->serverVar($name);

        return \is_string($value) ? $value : $default;
    }

    protected function serverVar(string $name): mixed
    {
        return $_SERVER[$name] ?? null;
    }

    protected function setStatusCode(int $code): void
    {
        http_response_code($code);
    }

    protected function shouldEmitBody(Response $response, ?Request $request = null): bool
    {
        if (self::statusHasNoContent($response->getStatusCode())) {
            return false;
        }
        $methodFromRequest = $request?->getMethod();
        if (!\is_string($methodFromRequest) || $methodFromRequest === '') {
            $methodFromRequest = $this->serverString('REQUEST_METHOD', HttpMethodEnum::GET->value);
        }

        return HttpMethodEnum::normalize($methodFromRequest) !== HttpMethodEnum::HEAD->value;
    }

    protected function shouldSendHeader(string $lowerName, string $value, bool $isHttp2): bool
    {
        if (!$isHttp2) {
            return true;
        }
        if (isset(self::H2_HOP_BY_HOP[$lowerName])) {
            return false;
        }

        return $lowerName !== 'te' || strtolower(trim($value)) === 'trailers';
    }

    protected function wantsChunked(
        bool $isHttp11,
        bool $allowsBody,
        Response $response,
        bool $isStreaming,
        ?int $size,
    ): bool {
        return false;
    }

    protected function write(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }
        if (!\is_resource($this->outputStream)) {
            $stream = \fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to open the response output stream.');
            }
            $this->outputStream = $stream;
        }

        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            $written = \fwrite($this->outputStream, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write response output.');
            }
            $offset += $written;
        }
    }

    private static function forbidsContentLength(int $status): bool
    {
        return ($status >= 100 && $status < 200)
            || $status === StatusEnum::NO_CONTENT->value;
    }

    private static function statusHasNoContent(int $status): bool
    {
        return ($status >= 100 && $status < 200)
            || $status === StatusEnum::NO_CONTENT->value
            || $status === StatusEnum::RESET_CONTENT->value
            || $status === StatusEnum::NOT_MODIFIED->value;
    }

    /** @param iterable<mixed> $out */
    private function emitIterableOutput(iterable $out): void
    {
        foreach ($out as $chunk) {
            if (!\is_string($chunk)) {
                throw new \UnexpectedValueException('Streaming response producers must yield strings.');
            }
            if ($chunk !== '') {
                $this->write($chunk);
            }
            $this->flush();
            if (\function_exists('connection_aborted') && connection_aborted()) {
                break;
            }
        }
    }
}
