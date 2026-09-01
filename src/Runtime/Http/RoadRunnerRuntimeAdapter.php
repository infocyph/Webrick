<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Closure;
use Generator;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Interop\Psr7\PsrBodyStreamAdapter;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/** RoadRunner worker adapter with bootstrap-selected responder. */
final readonly class RoadRunnerRuntimeAdapter implements RuntimeAdapterInterface
{
    /** @var Closure(int,string|Generator,array<string,list<string>>,bool):void */
    private Closure $respond;

    private RuntimeCapabilities $runtimeCapabilities;

    private bool $sendfileMiddleware;

    /** @param callable(int,string|Generator,array<string,list<string>>,bool):void $respond */
    public function __construct(
        callable $respond,
        bool $sendfileMiddleware = false,
        bool $transportCompression = false,
        bool $transportRequestLimits = false,
    ) {
        $this->respond = Closure::fromCallable($respond);
        $this->runtimeCapabilities = new RuntimeCapabilities(
            name: 'roadrunner',
            persistent: true,
            concurrent: false,
            nativeStreaming: true,
            nativeFile: $sendfileMiddleware,
            transportCompression: $transportCompression,
            transportRequestLimits: $transportRequestLimits,
        );
        $this->sendfileMiddleware = $sendfileMiddleware;
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
        unset($nativeResponse);

        if (!$nativeRequest instanceof ServerRequestInterface) {
            throw new RuntimeException('RoadRunner runtime requires a PSR-style server request object.');
        }

        $server = self::server($nativeRequest);
        $parsedResolved = false;
        $parsed = null;
        $resolveParsed = static function () use ($nativeRequest, &$parsedResolved, &$parsed): array|object|null {
            if (!$parsedResolved) {
                $parsed = self::parsedBody($nativeRequest);
                $parsedResolved = true;
            }

            return $parsed;
        };
        $form = RoutingFormInput::resolve(
            $server,
            static function () use ($resolveParsed): array {
                $value = $resolveParsed();

                return is_array($value) ? self::stringMapResult($value) : [];
            },
        );

        return new RuntimeRequestContext(
            RoutingInput::fromServer($server, $withHost, $form),
            static fn(): Request => TransportRequestFactory::fromParts(
                $server,
                self::headers($nativeRequest),
                self::body($nativeRequest),
                $resolveParsed(),
                self::normaliseUploadedFiles($nativeRequest->getUploadedFiles()),
                self::stringMapResult($nativeRequest->getQueryParams()),
                self::stringMapResult($nativeRequest->getCookieParams()),
            ),
            $this->runtimeCapabilities,
            $nativeRequest,
        );
    }

    public function write(Response $response, RuntimeRequestContext $context): void
    {
        $nativeRequest = $context->nativeRequest;
        $http2 = $nativeRequest instanceof ServerRequestInterface
            && str_starts_with($nativeRequest->getProtocolVersion(), '2');
        $headers = ResponseWriterSupport::headerMap($response, $http2);
        $size = ResponseWriterSupport::knownLength($response);
        if (!isset($headers['Content-Length']) && $size !== null) {
            $headers['Content-Length'] = [(string) $size];
        }

        if (!ResponseWriterSupport::allowsBody($response, $context)) {
            ($this->respond)($response->getStatusCode(), '', $headers, true);

            return;
        }

        $file = $response->getFileBody();
        if (
            $file !== null
            && $this->sendfileMiddleware
            && $file->offset() === 0
            && $file->length() === $file->sourceSize()
        ) {
            $headers['X-Sendfile'] = [$file->path()];
            ($this->respond)($response->getStatusCode(), '', $headers, true);

            return;
        }

        $string = $response->getStringBody();
        if ($string !== null && !$response->isStreaming()) {
            ($this->respond)($response->getStatusCode(), $string, $headers, true);

            return;
        }

        ($this->respond)(
            $response->getStatusCode(),
            self::generator(ResponseWriterSupport::chunks($response)),
            $headers,
            true,
        );
    }

    private static function body(ServerRequestInterface $request): BodyStream
    {
        return new PsrBodyStreamAdapter($request->getBody());
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function copyHeader(array &$server, ServerRequestInterface $request, string $name, string $target): void
    {
        $value = $request->getHeaderLine($name);
        if ($value !== '') {
            $server[$target] = $value;
        }
    }

    /** @param iterable<string> $chunks */
    private static function generator(iterable $chunks): Generator
    {
        yield from $chunks;
    }

    /**
     * @return array<string,list<string>>
     */
    private static function headers(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (is_string($name)) {
                $headers[$name] = array_values($values);
            }
        }

        return $headers;
    }

    /**
     * @param array<array-key,mixed> $files
     * @return array<string,UploadedFile|array<array-key,mixed>>
     */
    private static function mapUploadedFiles(array $files): array
    {
        $out = [];
        foreach ($files as $name => $file) {
            if (!is_string($name)) {
                continue;
            }
            $value = self::normaliseUploadedFile($file);
            if ($value !== null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @return UploadedFile|array<array-key,mixed>|null */
    private static function normaliseUploadedFile(mixed $file): UploadedFile|array|null
    {
        if (is_array($file)) {
            $out = [];
            foreach ($file as $key => $entry) {
                $value = self::normaliseUploadedFile($entry);
                if ($value !== null) {
                    $out[$key] = $value;
                }
            }

            return $out;
        }
        if (!$file instanceof UploadedFileInterface) {
            return null;
        }

        return new UploadedFile(
            new PsrBodyStreamAdapter($file->getStream()),
            $file->getSize(),
            $file->getError(),
            $file->getClientFilename(),
            $file->getClientMediaType(),
        );
    }

    /**
     * @return array<string,UploadedFile|array<array-key,mixed>>
     */
    private static function normaliseUploadedFiles(mixed $files): array
    {
        return is_array($files) ? self::mapUploadedFiles($files) : [];
    }

    /** @return array<string,mixed>|object|null */
    private static function parsedBody(ServerRequestInterface $request): array|object|null
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return self::stringMapResult($body);
        }

        return is_object($body) ? $body : null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function server(ServerRequestInterface $request): array
    {
        $server = self::stringMap($request->getServerParams());
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();
        $requestUri = ($path === '' ? '/' : $path) . ($query === '' ? '' : '?' . $query);

        $server['REQUEST_METHOD'] = $request->getMethod();
        $server['REQUEST_URI'] = $requestUri;
        $server['REQUEST_SCHEME'] = $uri->getScheme();
        $server['SERVER_PROTOCOL'] = 'HTTP/' . $request->getProtocolVersion();
        $host = $uri->getHost();
        $port = $uri->getPort();
        if ($host !== '') {
            $server['HTTP_HOST'] = $host . ($port !== null ? ':' . $port : '');
        }

        self::copyHeader($server, $request, 'Content-Type', 'CONTENT_TYPE');
        self::copyHeader($server, $request, 'X-HTTP-Method-Override', 'HTTP_X_HTTP_METHOD_OVERRIDE');
        self::copyHeader($server, $request, 'HTTP-Method-Override', 'HTTP_HTTP_METHOD_OVERRIDE');
        self::copyHeader($server, $request, 'Forwarded', 'HTTP_FORWARDED');
        self::copyHeader($server, $request, 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR');
        self::copyHeader($server, $request, 'X-Forwarded-Host', 'HTTP_X_FORWARDED_HOST');
        self::copyHeader($server, $request, 'X-Forwarded-Port', 'HTTP_X_FORWARDED_PORT');
        self::copyHeader($server, $request, 'X-Forwarded-Proto', 'HTTP_X_FORWARDED_PROTO');

        return $server;
    }

    /**
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
    private static function stringMap(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function stringMapResult(mixed $value): array
    {
        return is_array($value) ? self::stringMap($value) : [];
    }
}
