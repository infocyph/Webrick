<?php

// src/Response/Emitter/BaseEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
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

    /** @var resource|null */
    private mixed $outputStream = null;

    /**
     * Emit the response to the current IO target.
     *
     * This method detects whether the response body is a stream or not,
     * and emits the response accordingly.
     *
     * If the response body is a stream, it will be emitted in chunks
     * of at most {@see CHUNK_SIZE} bytes until the stream is exhausted.
     *
     * If the response body is not a stream, it will be emitted in its entirety.
     * @param Response $response
     * @param ?Request $request
     */
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

    /**
     * Checks if the current request allows a response body.
     *
     * This method takes into account the HTTP status code and the request method.
     * If the status code is 204 or 304, or if the request method is HEAD, it returns false.
     * Otherwise, it returns true.
     *
     * @return bool True if the current request allows a response body, false otherwise.
     * @param Response $response
     */
    protected function allowsBodyForCurrentRequest(Response $response): bool
    {
        $code = $response->getStatusCode();
        $method = HttpMethodEnum::normalize($this->serverString('REQUEST_METHOD', HttpMethodEnum::GET->value));

        return !\in_array($code, [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value], true)
            && $method !== HttpMethodEnum::HEAD->value;
    }

    /**
     * Emits the entire body stream to the current output stream.
     * This method is designed to be used with large files, as it will
     * read the file in chunks and write them to the output stream
     * directly, without storing the entire file in memory.
     *
     * The method will call rewind on the stream if it is seekable, and then
     * read the stream in chunks of self::CHUNK_SIZE bytes. It will
     * write each chunk to the output stream, and then call flush on
     * the output stream to ensure that the data is written to the
     * underlying stream.
     * @param BodyStream $body
     */
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

    /**
     * Emits a small response body to the current output stream.
     *
     * If the body is seekable, it will be rewound to the beginning
     * before being emitted. The body will be emitted directly to the
     * output stream, without storing the entire body in memory.
     *
     * This method is designed to be used with small files, as it will
     * read the file in chunks and write them to the output stream
     * directly, without storing the entire file in memory.
     * @param BodyStream $body
     */
    protected function emitSmall(BodyStream $body): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $this->write((string) $body);
    }

    /**
     * Emits a response body to the current output stream.
     *
     * If the response has a producer, it will be called and the result
     * will be emitted to the output stream. If the result is a generator
     * or an iterable, it will be iterated over and each value will be emitted
     * to the output stream. If the result is a string, it will be emitted
     * directly to the output stream.
     *
     * This method is designed to be used with large files, as it will
     * read the file in chunks and write them to the output stream
     * directly, without storing the entire file in memory.
     * @param Response $response
     */
    protected function emitStreaming(Response $response): void
    {
        $this->reduceOutputBuffering();

        $fn = $response->getProducer();
        $out = $fn ? $fn() : [];

        if ($out instanceof \Generator || is_iterable($out)) {
            $this->emitIterableOutput($out);

            return;
        }

        $this->emitScalarOutput($out);
    }

    /**
     * Filters out headers that should not be sent based on the HTTP protocol version.
     *
     * @param Response $response The response to filter headers from
     * @param bool $isHttp2 Whether the response is being sent over HTTP/2
     * @return \Generator<array{0:string, 1:string}> A generator that yields headers to send
     */
    protected function filteredHeaderIterator(Response $response, bool $isHttp2): \Generator
    {
        foreach ($response->getHeaders() as $name => $values) {
            $lname = strtolower($name);
            foreach ($values as $v) {
                $value = $v;
                if ($this->shouldSendHeader($lname, $value, $isHttp2)) {
                    yield [$name, $value];
                }
            }
        }
    }

    /**
     * Finishes the request with the best available method.
     *
     * If FPM is available, calls fastcgi_finish_request().
     * If LiteSpeed is available, calls litespeed_finish_request().
     * If FrankenPhp is available, calls frankenphp_finish_request().
     * Otherwise, does nothing.
     */
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

            return;
        }
    }

    /**
     * Flushes the current output buffer (as configured by php.ini).
     */
    protected function flush(): void
    {
        flush();
    }

    /**
     * Returns true if the HTTP headers have already been sent.
     *
     * This method should only be used by advanced users who know what they are doing.
     * Most users should use the higher-level API methods instead.
     *
     * @return bool True if the HTTP headers have already been sent, false otherwise.
     */
    protected function headersAlreadySent(): bool
    {
        return headers_sent();
    }

    /**
     * Returns true if the given body stream is a small temporary stream that can be emitted directly to the current output stream.
     *
     * A small temporary stream is a stream that is stored in memory and is smaller than the chunk size (CHUNK_SIZE).
     * If the body stream is seekable, it will be rewound to the beginning before being emitted.
     * @param BodyStream $body
     * @param ?int $size
     */
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

    /**
     * Reduces output buffering to a minimum by:
     *  - Ending all ob_*() calls with ob_end_flush()
     *  - Disabling implicit output buffering with ob_implicit_flush(true)
     *
     * @see http://php.net/manual/en/ref.outcontrol.php
     * @see https://stackoverflow.com/questions/8380623/why-does-php-buffer-output
     */
    protected function reduceOutputBuffering(): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
    }

    /**
     * Removes the X-Powered-By header from the response.
     *
     * This method is useful for hiding the server software from the client.
     * It is recommended to use this method when serving responses to untrusted
     * clients.
     */
    protected function removePoweredByHeader(): void
    {
        header_remove('X-Powered-By');
    }

    /**
     * Sends the common headers for the response: status code, Server, and headers.
     * Removes the "X-Powered-By" header if present.
     * If the response body is allowed, sends the Content-Length header if known and not already set.
     * If the response body is allowed and the emitter wants to send the response body chunked,
     * sets the Transfer-Encoding: chunked header.
     *
     * @param int|null $size the known size of the response body, or null if unknown
     * @param bool $isStreaming whether the response body is a streaming resource
     * @param Response $response
     * @param ?bool $allowsBody
     */
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

        // Content-Length if known and allowed
        if (
            $allowsBody && !$isStreaming && $size !== null
            && !$response->hasHeader('Content-Length')
        ) {
            $this->sendRawHeader('Content-Length', (string) $size);
        }

        // Transfer-Encoding: chunked — opt-in only in emitters that need it.
        if ($this->wantsChunked($isHttp11, $allowsBody, $response, $isStreaming, $size)) {
            $this->sendRawHeader('Transfer-Encoding', 'chunked');
        }
    }

    /**
     * Sends a raw header to the output buffer.
     *
     * This method is a low-level output method that should only be used by
     * advanced users who know what they are doing. Most users should use
     * the higher-level `withHeader()` method instead.
     * @param string $name
     * @param string $value
     */
    protected function sendRawHeader(string $name, string $value): void
    {
        header("{$name}: {$value}", false);
    }

    /**
     * Returns the HTTP protocol version of the current request.
     *
     * Fallback value is 'HTTP/1.1' if the $_SERVER['SERVER_PROTOCOL'] is not set.
     *
     * @return string The HTTP protocol version of the current request.
     */
    protected function serverProtocol(): string
    {
        return $this->serverString('SERVER_PROTOCOL', 'HTTP/1.1');
    }

    protected function serverString(string $name, string $default = ''): string
    {
        $value = $this->serverVar($name);

        return \is_string($value) ? $value : $default;
    }

    /**
     * Retrieves a value from the $_SERVER superglobal.
     *
     * @param string $name The key to retrieve from $_SERVER.
     * @return mixed The value associated with the key or the entire $_SERVER array if `$name` is `null`.
     * @param string $name
     */
    protected function serverVar(string $name): mixed
    {
        return $_SERVER[$name] ?? null;
    }

    /**
     * Sets the HTTP status code of the response.
     *
     * This method is a low-level response method that should only be used by
     * advanced users who know what they are doing. Most users should use
     * the higher-level `withStatus()` method instead.
     * @param int $code
     */
    protected function setStatusCode(int $code): void
    {
        http_response_code($code);
    }

    /**
     * Checks if the current request allows a response body.
     *
     * This method takes into account the HTTP status code and the request method.
     * If the status code is 204 or 304, or if the request method is HEAD, it returns false.
     * Otherwise, it returns true.
     *
     * @return bool True if the current request allows a response body, false otherwise.
     * @param Response $response
     * @param ?Request $request
     */
    protected function shouldEmitBody(Response $response, ?Request $request = null): bool
    {
        if (\in_array($response->getStatusCode(), [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value], true)) {
            return false;
        }
        $methodFromRequest = $request?->getMethod();
        if (!\is_string($methodFromRequest) || $methodFromRequest === '') {
            $methodFromRequest = $this->serverString('REQUEST_METHOD', HttpMethodEnum::GET->value);
        }
        $method = HttpMethodEnum::normalize($methodFromRequest);

        return $method !== HttpMethodEnum::HEAD->value;
    }

    /**
     * Returns true if the header should be sent, false otherwise.
     *
     * In HTTP/2, the following headers are not sent:
     *   - All headers listed in [RFC 7540, Section 8.1.2](https://tools.ietf.org/html/rfc7540#section-8.1.2)
     *   - TE, unless the value is "trailers"
     *
     * @param string $lowerName the lower-cased header name
     * @param string $value the header value
     * @param bool $isHttp2 whether the request is HTTP/2
     * @return bool whether the header should be sent
     */
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
     * Determine if the emitter wants to send the response body chunked.
     *
     * Emitters that genuinely need it (e.g. classic FPM under HTTP/1.1)
     * can override and return true under safe conditions.
     *
     * @param bool $isHttp11 if the response is being sent over HTTP/1.1
     * @param bool $allowsBody if the response is allowed to have a body
     * @param Response $response the response to check
     * @param bool $isStreaming if the response body is a streaming resource
     * @param int|null $size the known size of the response body, or null if unknown
     * @return bool true if the emitter wants to send the response body chunked, false otherwise
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

    /**
     * Writes a chunk of the response directly to the output buffer.
     *
     * This method should never be overridden by subclasses, as it is
     * the lowest-level output method that must be used by all emitters.
     * @param string $chunk
     */
    protected function write(string $chunk): void
    {
        if (!\is_resource($this->outputStream)) {
            $stream = \fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to open the response output stream.');
            }
            $this->outputStream = $stream;
        }

        \fwrite($this->outputStream, $chunk);
    }

    /**
     * @param iterable<mixed> $out
     */
    private function emitIterableOutput(iterable $out): void
    {
        foreach ($out as $chunk) {
            $this->emitScalarOutput($chunk);
            $this->flush();
            if (\function_exists('connection_aborted') && connection_aborted()) {
                break;
            }
        }
    }

    private function emitScalarOutput(mixed $out): void
    {
        if (\is_string($out)) {
            if ($out !== '') {
                $this->write($out);
            }

            return;
        }
        if (\is_scalar($out)) {
            $asString = (string) $out;
            if ($asString !== '') {
                $this->write($asString);
            }
        }
    }
}
