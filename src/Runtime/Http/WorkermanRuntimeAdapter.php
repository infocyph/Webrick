<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use RuntimeException;

/** Workerman 4+ adapter with compatibility resolved once at bootstrap. */
final readonly class WorkermanRuntimeAdapter implements RuntimeAdapterInterface
{
    private RuntimeCapabilities $runtimeCapabilities;

    private function __construct(
        /** @var class-string */
        private string $responseClass,
        /** @var class-string */
        private string $chunkClass,
        bool $transportCompression,
        bool $transportRequestLimits,
    ) {
        $this->runtimeCapabilities = new RuntimeCapabilities(
            name: 'workerman',
            persistent: true,
            concurrent: false,
            nativeStreaming: true,
            nativeFile: true,
            transportCompression: $transportCompression,
            transportRequestLimits: $transportRequestLimits,
        );
    }

    /**
     * Resolve Workerman's HTTP response protocol classes once during worker boot.
     */
    public static function current(
        bool $transportCompression = false,
        bool $transportRequestLimits = false,
    ): self {
        $response = 'Workerman\\Protocols\\Http\\Response';
        $chunk = 'Workerman\\Protocols\\Http\\Chunk';
        if (!class_exists($response) || !class_exists($chunk)) {
            throw new RuntimeException('Workerman 4+ HTTP Response and Chunk classes are required.');
        }

        return new self($response, $chunk, $transportCompression, $transportRequestLimits);
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
            throw new RuntimeException('Workerman runtime requires Request and Connection objects.');
        }

        $server = WorkermanNativeRequest::server($nativeRequest, $nativeResponse);
        $postResolved = false;
        $post = [];
        $resolvePost = static function () use ($nativeRequest, &$postResolved, &$post): array {
            if (!$postResolved) {
                $post = WorkermanNativeRequest::post($nativeRequest);
                $postResolved = true;
            }

            return $post;
        };
        $form = RoutingFormInput::resolve($server, $resolvePost);

        return new RuntimeRequestContext(
            RoutingInput::fromServer($server, $withHost, $form),
            static fn(): Request => TransportRequestFactory::fromParts(
                $server,
                WorkermanNativeRequest::headers($nativeRequest),
                WorkermanNativeRequest::rawBody($nativeRequest),
                $resolvePost(),
                WorkermanNativeRequest::files($nativeRequest),
                WorkermanNativeRequest::query($nativeRequest),
                WorkermanNativeRequest::cookies($nativeRequest),
            ),
            $this->runtimeCapabilities,
            $nativeRequest,
            $nativeResponse,
        );
    }

    public function write(Response $response, RuntimeRequestContext $context): void
    {
        $connection = $context->nativeResponse;
        if (!is_object($connection)) {
            throw new RuntimeException('Workerman connection is unavailable.');
        }

        $headers = $this->headerMap($response);
        $size = ResponseWriterSupport::knownLength($response);
        if (!$response->hasHeader('Content-Length') && $size !== null) {
            $headers['Content-Length'] = (string) $size;
        }

        if (!ResponseWriterSupport::allowsBody($response, $context)) {
            $this->send($connection, $this->response($response->getStatusCode(), $headers, ''));

            return;
        }

        $file = $response->getFileBody();
        if ($file !== null && $file->offset() === 0 && $file->length() === filesize($file->path())) {
            $native = $this->response($response->getStatusCode(), $headers, '')->withFile($file->path());
            $this->send($connection, $native);

            return;
        }

        $string = $response->getStringBody();
        if ($string !== null && !$response->isStreaming()) {
            $this->send($connection, $this->response($response->getStatusCode(), $headers, $string));

            return;
        }

        unset($headers['Content-Length']);
        $headers['Transfer-Encoding'] = 'chunked';
        $this->send($connection, $this->response($response->getStatusCode(), $headers, ''));
        $chunkClass = $this->chunkClass;
        foreach (ResponseWriterSupport::chunks($response) as $chunk) {
            $this->send($connection, new $chunkClass($chunk));
        }
        $this->send($connection, new $chunkClass(''));
    }

    /**
     * @return array<string,string|array<int,string>>
     */
    private function headerMap(Response $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $headers;
    }

    /**
     * @param array<string,string|array<int,string>> $headers
     */
    private function response(int $status, array $headers, string $body): object
    {
        $class = $this->responseClass;

        return new $class($status, $headers, $body);
    }

    private function send(object $connection, object|string $payload): void
    {
        if ($connection->send($payload) === false) {
            throw new RuntimeException('Workerman connection send failed.');
        }
    }
}
