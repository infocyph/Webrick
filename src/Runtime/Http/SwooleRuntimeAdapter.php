<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use RuntimeException;

/** Swoole/OpenSwoole runtime adapter with request-local native handles. */
final readonly class SwooleRuntimeAdapter implements RuntimeAdapterInterface
{
    private RuntimeCapabilities $runtimeCapabilities;

    private function __construct(
        string $name,
        bool $transportCompression,
        bool $transportRequestLimits,
    ) {
        $this->runtimeCapabilities = new RuntimeCapabilities(
            name: $name,
            persistent: true,
            concurrent: true,
            nativeStreaming: true,
            nativeFile: true,
            transportCompression: $transportCompression,
            transportRequestLimits: $transportRequestLimits,
        );
    }

    public static function openSwoole(bool $transportCompression = false, bool $transportRequestLimits = false): self
    {
        return new self('openswoole', $transportCompression, $transportRequestLimits);
    }

    public static function swoole(bool $transportCompression = false, bool $transportRequestLimits = false): self
    {
        return new self('swoole', $transportCompression, $transportRequestLimits);
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities;
    }

    public function context(mixed $nativeRequest = null, mixed $nativeResponse = null, bool $withHost = false): RuntimeRequestContext
    {
        if (!is_object($nativeRequest) || !is_object($nativeResponse)) {
            throw new RuntimeException('Swoole runtime requires native request and response objects.');
        }

        $server = SwooleNativeRequest::server($nativeRequest);
        $routingForm = RoutingFormInput::resolve(
            $server,
            static fn(): array => SwooleNativeRequest::arrayProperty($nativeRequest, 'post'),
        );

        return new RuntimeRequestContext(
            RoutingInput::fromServer($server, $withHost, $routingForm),
            static fn(): Request => TransportRequestFactory::fromParts(
                $server,
                SwooleNativeRequest::headers($nativeRequest),
                SwooleNativeRequest::rawBody($nativeRequest),
                SwooleNativeRequest::arrayProperty($nativeRequest, 'post'),
                SwooleNativeRequest::arrayProperty($nativeRequest, 'files'),
                SwooleNativeRequest::arrayProperty($nativeRequest, 'get'),
                SwooleNativeRequest::arrayProperty($nativeRequest, 'cookie'),
            ),
            $this->runtimeCapabilities,
            $nativeRequest,
            $nativeResponse,
        );
    }

    public function write(Response $response, RuntimeRequestContext $context): void
    {
        $native = $context->nativeResponse;
        $request = $context->nativeRequest;
        if (!is_object($native) || !is_object($request)) {
            throw new RuntimeException('Swoole transport handles are unavailable.');
        }

        $this->setStatus($native, $response->getStatusCode());
        $this->writeHeaders($native, $response, SwooleNativeRequest::isHttp2($request));

        if (!ResponseWriterSupport::allowsBody($response, $context)) {
            $this->end($native, '');

            return;
        }

        $this->writeBody($native, $response);
    }

    private function call(object $target, string $method, mixed ...$arguments): mixed
    {
        if (!method_exists($target, $method)) {
            throw new RuntimeException("Swoole native response does not support {$method}().");
        }

        return $target->{$method}(...$arguments);
    }

    private function end(object $native, ?string $body = null): void
    {
        $arguments = $body === null ? [] : [$body];
        if ($this->call($native, 'end', ...$arguments) === false) {
            throw new RuntimeException('Swoole response end failed.');
        }
    }

    private function setStatus(object $native, int $status): void
    {
        if ($this->call($native, 'status', $status) === false) {
            throw new RuntimeException('Swoole response status failed.');
        }
    }

    private function writeBody(object $native, Response $response): void
    {
        $file = $response->getFileBody();
        if ($file !== null) {
            if ($this->call($native, 'sendfile', $file->path(), $file->offset(), $file->length()) === false) {
                throw new RuntimeException('Swoole sendfile() failed.');
            }

            return;
        }

        $string = $response->getStringBody();
        if ($string !== null && !$response->isStreaming()) {
            $this->end($native, $string);

            return;
        }

        foreach (ResponseWriterSupport::chunks($response) as $chunk) {
            if ($this->call($native, 'write', $chunk) === false) {
                throw new RuntimeException('Swoole response write failed.');
            }
        }
        $this->end($native);
    }

    /** @param list<string> $values */
    private function writeHeader(object $native, string $name, array $values): void
    {
        if ($values === []) {
            return;
        }
        if ($this->call($native, 'header', $name, count($values) === 1 ? $values[0] : $values) === false) {
            throw new RuntimeException("Swoole response header failed: {$name}");
        }
    }

    private function writeHeaders(object $native, Response $response, bool $http2): void
    {
        $headers = ResponseWriterSupport::headerMap($response, $http2);
        $size = ResponseWriterSupport::knownLength($response);
        if (!isset($headers['Content-Length']) && $size !== null) {
            $headers['Content-Length'] = [(string) $size];
        }
        foreach ($headers as $name => $values) {
            $this->writeHeader($native, $name, $values);
        }
    }
}
