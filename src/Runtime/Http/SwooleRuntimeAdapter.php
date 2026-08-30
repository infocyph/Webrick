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

    public static function openSwoole(
        bool $transportCompression = false,
        bool $transportRequestLimits = false,
    ): self {
        return new self('openswoole', $transportCompression, $transportRequestLimits);
    }

    public static function swoole(
        bool $transportCompression = false,
        bool $transportRequestLimits = false,
    ): self {
        return new self('swoole', $transportCompression, $transportRequestLimits);
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities;
    }

    public function context(
        mixed $nativeRequest = null,
        mixed $nativeResponse = null,
        bool $withHost = false,
    ): RuntimeRequestContext {
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

        if ($native->status($response->getStatusCode()) === false) {
            throw new RuntimeException('Swoole response status failed.');
        }

        $http2 = SwooleNativeRequest::isHttp2($request);
        foreach ($response->getHeaders() as $name => $values) {
            $allowed = [];
            foreach ($values as $value) {
                if (ResponseWriterSupport::headerAllowed($name, $value, $http2)) {
                    $allowed[] = $value;
                }
            }
            if ($allowed !== [] && $native->header($name, count($allowed) === 1 ? $allowed[0] : $allowed) === false) {
                throw new RuntimeException("Swoole response header failed: {$name}");
            }
        }

        $size = ResponseWriterSupport::knownLength($response);
        if (
            !$response->hasHeader('Content-Length')
            && $size !== null
            && $native->header('Content-Length', (string) $size) === false
        ) {
            throw new RuntimeException('Swoole Content-Length header failed.');
        }

        if (!ResponseWriterSupport::allowsBody($response, $context)) {
            if ($native->end('') === false) {
                throw new RuntimeException('Swoole response end failed.');
            }

            return;
        }

        $file = $response->getFileBody();
        if ($file !== null) {
            if ($native->sendfile($file->path(), $file->offset(), $file->length()) === false) {
                throw new RuntimeException('Swoole sendfile() failed.');
            }

            return;
        }

        $string = $response->getStringBody();
        if ($string !== null && !$response->isStreaming()) {
            if ($native->end($string) === false) {
                throw new RuntimeException('Swoole response end failed.');
            }

            return;
        }

        foreach (ResponseWriterSupport::chunks($response) as $chunk) {
            if ($native->write($chunk) === false) {
                throw new RuntimeException('Swoole response write failed.');
            }
        }
        if ($native->end() === false) {
            throw new RuntimeException('Swoole response end failed.');
        }
    }
}
