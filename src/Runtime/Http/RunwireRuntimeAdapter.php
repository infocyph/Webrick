<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use RuntimeException;

/** Adapts released Runwire HTTP request/writer contracts to Webrick. */
final readonly class RunwireRuntimeAdapter implements RuntimeAdapterInterface
{
    private RuntimeCapabilities $runtimeCapabilities;

    public function __construct(
        bool $persistent = true,
        bool $concurrent = true,
        bool $transportCompression = false,
        bool $transportRequestLimits = true,
    ) {
        $this->runtimeCapabilities = new RuntimeCapabilities(
            name: 'runwire',
            persistent: $persistent,
            concurrent: $concurrent,
            nativeStreaming: true,
            nativeFile: false,
            transportCompression: $transportCompression,
            transportRequestLimits: $transportRequestLimits,
            nativeRequestStreaming: true,
            cancellationVisibility: true,
            transportDrainVisibility: true,
        );
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities;
    }

    public function context(mixed $nativeRequest = null, mixed $nativeResponse = null, bool $withHost = false): RuntimeRequestContext
    {
        if (!$nativeRequest instanceof HttpRequest || !$nativeResponse instanceof ResponseWriterInterface) {
            throw new RuntimeException('Runwire runtime requires HttpRequest and ResponseWriterInterface handles.');
        }

        $routingServer = self::serverParams($nativeRequest, false);
        $runwireContext = $nativeRequest->context;
        $execution = new RuntimeRequestExecution(
            requestId: $runwireContext->requestId,
            startMonotonicNanoseconds: $runwireContext->startMonotonicNanoseconds,
            deadlineMonotonicNanoseconds: $runwireContext->deadline()->monotonicNanoseconds,
            cancelled: static fn(): bool => $runwireContext->cancelled(),
            cancellationReason: static fn(): ?string => $runwireContext->cancellation->reason()?->value,
        );

        return new RuntimeRequestContext(
            RoutingInput::fromServer($routingServer, $withHost),
            static fn(): Request => TransportRequestFactory::fromParts(
                self::serverParams($nativeRequest, true),
                self::headerArray($nativeRequest->headers),
                new RunwireRequestBodyStream($nativeRequest->body),
                cookies: self::cookies($nativeRequest->headers),
            ),
            $this->runtimeCapabilities,
            $nativeRequest,
            $nativeResponse,
            $execution,
        );
    }

    public function write(Response $response, RuntimeRequestContext $context): void
    {
        $request = $context->nativeRequest;
        $writer = $context->nativeResponse;
        if (!$request instanceof HttpRequest || !$writer instanceof ResponseWriterInterface) {
            throw new RuntimeException('Runwire transport handles are unavailable.');
        }
        if ($writer->isStarted() || $writer->isEnded()) {
            throw new RuntimeException('Runwire response writer must be unused when Webrick begins response output.');
        }

        $headers = self::responseHeaders($response, $request->version !== ProtocolVersion::HTTP_1_1);
        self::assertWritable($writer->start($response->getStatusCode(), $headers), 'start');

        if (!ResponseWriterSupport::allowsBody($response, $context)) {
            self::finish($writer);

            return;
        }

        $string = $response->getStringBody();
        if ($string !== null && !$response->isStreaming()) {
            self::finish($writer, $string);

            return;
        }

        foreach (ResponseWriterSupport::chunks($response) as $chunk) {
            if ($request->context->cancelled()) {
                throw new RuntimeException('Runwire request was cancelled during Webrick response production.');
            }
            self::assertWritable($writer->write($chunk), 'write');
        }
        self::finish($writer);
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    private static function appendHeaderServerParams(array $server, Headers $headers): array
    {
        $values = [];
        foreach ($headers->fields() as $field) {
            $values[$field->name][] = $field->value;
        }
        foreach ($values as $name => $entries) {
            $key = match ($name) {
                'content-length' => 'CONTENT_LENGTH',
                'content-type' => 'CONTENT_TYPE',
                default => 'HTTP_' . strtoupper(str_replace('-', '_', $name)),
            };
            $server[$key] = implode($name === 'cookie' ? '; ' : ', ', $entries);
        }

        return $server;
    }

    private static function assertWritable(WriteResult $result, string $operation): void
    {
        if (!$result->accepted()) {
            throw new RuntimeException("Runwire response {$operation} was rejected: {$result->state->value}.");
        }
        if ($result->pressured()) {
            throw new RuntimeException("Runwire response {$operation} requires drain continuation before more output.");
        }
    }

    /** @return array<string,mixed> */
    private static function baseServerParams(HttpRequest $request): array
    {
        $server = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => self::requestUri($request->target),
            'SERVER_PROTOCOL' => 'HTTP/' . $request->version->value,
            'REQUEST_SCHEME' => $request->encrypted ? 'https' : 'http',
        ];
        if ($request->encrypted) {
            $server['HTTPS'] = 'on';
        }
        $host = $request->headers->first('host') ?? self::targetAuthority($request->target);
        if ($host !== null && $host !== '') {
            $server['HTTP_HOST'] = $host;
            $server['SERVER_NAME'] = self::endpointParts($host)[0] ?? $host;
        }

        [$remoteAddress, $remotePort] = self::endpointParts($request->peerAddress);
        if ($remoteAddress !== null) {
            $server['REMOTE_ADDR'] = $remoteAddress;
        }
        if ($remotePort !== null) {
            $server['REMOTE_PORT'] = (string) $remotePort;
        }
        [$localAddress, $localPort] = self::endpointParts($request->localAddress);
        if ($localAddress !== null) {
            $server['SERVER_ADDR'] = $localAddress;
        }
        if ($localPort !== null) {
            $server['SERVER_PORT'] = (string) $localPort;
        }

        foreach (['content-type', 'x-http-method-override', 'http-method-override'] as $name) {
            $value = $request->headers->first($name);
            if ($value === null) {
                continue;
            }
            $key = match ($name) {
                'content-type' => 'CONTENT_TYPE',
                'x-http-method-override' => 'HTTP_X_HTTP_METHOD_OVERRIDE',
                default => 'HTTP_HTTP_METHOD_OVERRIDE',
            };
            $server[$key] = $value;
        }

        return $server;
    }

    /** @return array<string,mixed> */
    private static function cookies(Headers $headers): array
    {
        $cookies = [];
        foreach ($headers->all('cookie') as $header) {
            foreach (explode(';', $header) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || !str_contains($pair, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $pair, 2);
                $name = trim($name);
                if ($name !== '' && !array_key_exists($name, $cookies)) {
                    $cookies[$name] = urldecode(trim($value));
                }
            }
        }

        return $cookies;
    }

    /** @return array{0:string|null,1:int|null} */
    private static function endpointParts(?string $endpoint): array
    {
        if ($endpoint === null || $endpoint === '') {
            return [null, null];
        }
        if ($endpoint[0] === '[' && preg_match('/^\[(?<host>[^\]]+)\](?::(?<port>\d{1,5}))?$/D', $endpoint, $matches) === 1) {
            return [
                $matches['host'],
                isset($matches['port'][0]) ? (int) $matches['port'] : null,
            ];
        }
        if (filter_var($endpoint, FILTER_VALIDATE_IP) !== false) {
            return [$endpoint, null];
        }
        if (substr_count($endpoint, ':') === 1) {
            [$host, $port] = explode(':', $endpoint, 2);
            if ($host !== '' && preg_match('/^\d{1,5}$/D', $port) === 1 && (int) $port <= 65_535) {
                return [$host, (int) $port];
            }
        }

        return [$endpoint, null];
    }

    private static function finish(ResponseWriterInterface $writer, string $finalChunk = ''): void
    {
        $result = $writer->end($finalChunk);
        if (!$result->accepted()) {
            throw new RuntimeException("Runwire response end was rejected: {$result->state->value}.");
        }
        if (!$writer->isEnded()) {
            throw new RuntimeException('Runwire response writer did not enter the ended state.');
        }
    }

    /** @return array<string,string|list<string>> */
    private static function headerArray(Headers $headers): array
    {
        $normalized = [];
        foreach ($headers->fields() as $field) {
            $normalized[$field->name][] = $field->value;
        }

        return $normalized;
    }

    private static function requestUri(string $target): string
    {
        if ($target === '' || $target === '*') {
            return $target === '' ? '/' : $target;
        }
        if ($target[0] === '/') {
            return $target;
        }

        $parts = parse_url($target);
        if ($parts === false || !isset($parts['scheme'])) {
            return $target;
        }
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $path . $query;
    }

    private static function responseHeaders(Response $response, bool $http2Or3): Headers
    {
        $headers = ResponseWriterSupport::headerMap($response, $http2Or3);
        $runwire = Headers::fromArray($headers);
        $size = ResponseWriterSupport::knownLength($response);
        if ($size !== null && !$runwire->has('content-length')) {
            $headers['Content-Length'] = [(string) $size];
            $runwire = Headers::fromArray($headers);
        }

        return $runwire;
    }

    /** @return array<string,mixed> */
    private static function serverParams(HttpRequest $request, bool $withHeaders): array
    {
        $server = self::baseServerParams($request);

        return $withHeaders ? self::appendHeaderServerParams($server, $request->headers) : $server;
    }

    private static function targetAuthority(string $target): ?string
    {
        $parts = parse_url($target);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }
        $host = (string) $parts['host'];
        if (str_contains($host, ':') && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }

        return isset($parts['port']) ? $host . ':' . $parts['port'] : $host;
    }
}
