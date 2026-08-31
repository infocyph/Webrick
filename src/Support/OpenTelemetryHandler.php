<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Closure;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

final readonly class OpenTelemetryHandler
{
    private const string OTEL_GLOBALS = 'OpenTelemetry\\API\\Globals';

    private const string OTEL_SPAN_KIND_SERVER = 'OpenTelemetry\\API\\Trace\\SpanKind::KIND_SERVER';

    private const string OTEL_STATUS_ERROR = 'OpenTelemetry\\API\\Trace\\StatusCode::STATUS_ERROR';

    private const string OTEL_STATUS_OK = 'OpenTelemetry\\API\\Trace\\StatusCode::STATUS_OK';

    public function __construct(private TelemetryOptions $options) {}

    /**
     * @param Closure(Request):Response $next
     */
    public function handle(Request $req, Closure $next): Response
    {
        $startNs = hrtime(true);
        $span = $this->startServerSpan($req, $startNs);
        $scope = $this->callObject($span, 'activate');

        try {
            $this->addSpanAttributes($span, $req);
            [$traceId, $spanId] = $this->extractTraceContext($span);
            $requestId = $this->deriveRequestId($req);

            $req = $req
                ->withAttribute('trace.trace_id', $traceId)
                ->withAttribute('trace.span_id', $spanId)
                ->withAttribute('request_id', $requestId);
            $req = TraceContext::attach($req, true);

            $resp = $next($req);
            $this->addResponseAttributes($span, $resp);
            $this->setSpanStatus($span, $resp->getStatusCode());

            $durMs = (hrtime(true) - $startNs) / 1e6;
            $resp = TelemetrySupport::addTimingHeaders(
                $resp,
                $this->options->addXResponseTime,
                $this->options->addServerTiming,
                $durMs,
            );
            $resp = $this->addCorrelationHeaders($resp, $traceId, $requestId);
            $resp = TelemetrySupport::applyNelHeaders(
                $resp,
                $this->options->nelGroup,
                $this->options->nelEndpoint,
                $this->options->nelTtlSeconds,
                $this->options->nelIncludeSubdomains,
                $this->options->nelCollectSuccesses,
            );
            TelemetrySupport::logAccess(
                $this->options->log,
                $req,
                $resp,
                $durMs,
                $spanId,
                $traceId,
                $requestId,
                'otel',
            );

            return $resp;
        } catch (\Throwable $e) {
            $this->call($span, 'recordException', [$e]);
            $this->call($span, 'setStatus', [$this->otelStatusError(), $e->getMessage()]);

            throw $e;
        } finally {
            $this->call($span, 'end');
            if (method_exists($scope, 'detach')) {
                $this->call($scope, 'detach');
            }
        }
    }

    private function addCorrelationHeaders(Response $resp, string $traceId, ?string $requestId): Response
    {
        return TelemetrySupport::addCorrelationHeaders(
            $resp,
            $this->options->emitRequestId,
            $this->options->requestIdHeader,
            $requestId,
            $this->options->emitTraceIdHeader,
            $this->options->traceIdHeader,
            $traceId,
        );
    }

    private function addCustomAttributes(object $span, Request $req): void
    {
        $routeName = $req->getAttribute('route.name');
        if (is_string($routeName) && $routeName !== '') {
            $this->setSpanAttribute($span, 'http.route', $routeName);
        }

        $userId = TelemetrySupport::stringFromMixed($req->getAttribute('auth.user_id'));
        if ($userId !== null) {
            $this->setSpanAttribute($span, 'enduser.id', $userId);
        }
        $userRole = TelemetrySupport::stringFromMixed($req->getAttribute('auth.role'));
        if ($userRole !== null) {
            $this->setSpanAttribute($span, 'enduser.role', $userRole);
        }
        $clientType = TelemetrySupport::stringFromMixed($req->getAttribute('client.type'));
        if ($clientType !== null) {
            $this->setSpanAttribute($span, 'client.type', $clientType);
        }
        $apiVersion = TelemetrySupport::stringFromMixed($req->getAttribute('api.version'));
        if ($apiVersion !== null) {
            $this->setSpanAttribute($span, 'api.version', $apiVersion);
        }
        $trusted = $req->getAttribute('is_trusted_proxy');
        if (is_bool($trusted)) {
            $this->setSpanAttribute($span, 'http.client.is_trusted_proxy', $trusted);
        }
    }

    private function addNetworkAttributes(object $span, Request $req): void
    {
        $clientIp = TelemetrySupport::stringFromMixed($req->getAttribute('client_ip'));
        if ($clientIp === null) {
            $remote = $req->getServerParams()['REMOTE_ADDR'] ?? null;
            $clientIp = is_string($remote) && $remote !== '' ? $remote : null;
        }
        if ($clientIp !== null) {
            $this->setSpanAttribute($span, 'net.peer.ip', $clientIp);
        }

        $serverPort = $req->getUri()->getPort();
        if ($serverPort !== null) {
            $this->setSpanAttribute($span, 'net.host.port', $serverPort);
        }
        $protocolVersion = $req->getProtocolVersion();
        if ($protocolVersion !== '') {
            $this->setSpanAttribute($span, 'http.flavor', $protocolVersion);
        }
    }

    private function addResponseAttributes(object $span, Response $resp): void
    {
        $this->setSpanAttribute($span, 'http.status_code', $resp->getStatusCode());
        $contentLength = $resp->getBody()->getSize();
        if ($contentLength !== null) {
            $this->setSpanAttribute($span, 'http.response_content_length', $contentLength);
        }
        $contentType = $resp->getHeaderLine('Content-Type');
        if ($contentType !== '') {
            $this->setSpanAttribute($span, 'http.response_content_type', $contentType);
        }
    }

    private function addSpanAttributes(object $span, Request $req): void
    {
        $this->setSpanAttribute($span, 'http.method', $req->getMethod());
        $this->setSpanAttribute($span, 'http.target', $req->getUri()->getPath() ?: '/');
        $this->setSpanAttribute($span, 'http.scheme', $req->getUri()->getScheme());
        $this->setSpanAttribute($span, 'http.host', $req->getUri()->getHost());

        $url = (string) $req->getUri();
        if ($url !== '') {
            $this->setSpanAttribute($span, 'http.url', $url);
        }
        $userAgent = $req->getHeaderLine('User-Agent');
        if ($userAgent !== '') {
            $this->setSpanAttribute($span, 'http.user_agent', $userAgent);
        }
        $contentLength = $req->getHeaderLine('Content-Length');
        if ($contentLength !== '' && is_numeric($contentLength)) {
            $this->setSpanAttribute($span, 'http.request_content_length', (int) $contentLength);
        }

        $this->addNetworkAttributes($span, $req);
        $this->addCustomAttributes($span, $req);
        $this->setSpanAttribute($span, 'http.server_name', $this->options->otelServiceName);
    }

    private function buildSpanName(Request $req): string
    {
        $routeName = $req->getAttribute('route.name');

        return $req->getMethod() . ' ' . (is_string($routeName) && $routeName !== ''
            ? $routeName
            : ($req->getUri()->getPath() ?: '/'));
    }

    /**
     * @param list<mixed> $args
     */
    private function call(object $target, string $method, array $args = []): mixed
    {
        if (!method_exists($target, $method)) {
            throw new RuntimeException(sprintf('Method %s::%s() not available.', $target::class, $method));
        }

        return $target->{$method}(...$args);
    }

    /**
     * @param list<mixed> $args
     */
    private function callObject(object $target, string $method, array $args = []): object
    {
        $result = $this->call($target, $method, $args);
        if (!is_object($result)) {
            throw new RuntimeException(sprintf('Method %s::%s() did not return an object.', $target::class, $method));
        }

        return $result;
    }

    /**
     * @param list<mixed> $args
     */
    private function callStatic(string $class, string $method, array $args = []): mixed
    {
        if (!method_exists($class, $method)) {
            throw new RuntimeException(sprintf('Static method %s::%s() not available.', $class, $method));
        }

        return $class::$method(...$args);
    }

    /**
     * @param list<mixed> $args
     */
    private function callStaticObject(string $class, string $method, array $args = []): object
    {
        $result = $this->callStatic($class, $method, $args);
        if (!is_object($result)) {
            throw new RuntimeException(sprintf('Static method %s::%s() did not return an object.', $class, $method));
        }

        return $result;
    }

    private function deriveRequestId(Request $req): ?string
    {
        return TelemetrySupport::deriveRequestId(
            $req,
            $this->options->emitRequestId,
            $this->options->requestIdHeader,
            $this->options->respectExistingRequestId,
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function extractTraceContext(object $span): array
    {
        $context = $this->callObject($span, 'getContext');

        return [
            TelemetrySupport::stringFromMixed($this->call($context, 'getTraceId')) ?? '',
            TelemetrySupport::stringFromMixed($this->call($context, 'getSpanId')) ?? '',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function headersToCarrier(Request $req): array
    {
        $carrier = [];
        foreach ($req->getHeaders() as $name => $values) {
            $carrier[strtolower((string) $name)] = $values[0] ?? '';
        }

        return $carrier;
    }

    private function otelIntConstant(string $name, int $fallback, string $label): int
    {
        if (!defined($name)) {
            return $fallback;
        }
        $value = constant($name);
        if (!is_int($value)) {
            throw new RuntimeException("Invalid {$label} constant.");
        }

        return $value;
    }

    private function otelSpanKindServer(): int
    {
        return $this->otelIntConstant(self::OTEL_SPAN_KIND_SERVER, 1, 'OpenTelemetry SpanKind::KIND_SERVER');
    }

    private function otelStatusError(): int
    {
        return $this->otelIntConstant(self::OTEL_STATUS_ERROR, 2, 'OpenTelemetry StatusCode::STATUS_ERROR');
    }

    private function otelStatusOk(): int
    {
        return $this->otelIntConstant(self::OTEL_STATUS_OK, 1, 'OpenTelemetry StatusCode::STATUS_OK');
    }

    /**
     * @param bool|int|float|string|array<int|string,mixed>|null $value
     */
    private function setSpanAttribute(object $span, string $key, bool|int|float|string|array|null $value): void
    {
        $this->call($span, 'setAttribute', [$key, $value]);
    }

    private function setSpanStatus(object $span, int $statusCode): void
    {
        $series = StatusEnum::tryFrom($statusCode)?->series() ?? intdiv($statusCode, 100);
        if ($series === 5) {
            $this->call($span, 'setStatus', [$this->otelStatusError(), 'HTTP ' . $statusCode]);

            return;
        }

        $this->call($span, 'setStatus', [$this->otelStatusOk()]);
        if ($series === 4) {
            $this->setSpanAttribute($span, 'http.status_class', '4xx');
        }
    }

    private function startServerSpan(Request $req, int $startNs): object
    {
        if (!class_exists(self::OTEL_GLOBALS)) {
            throw new RuntimeException('OpenTelemetry Globals class not found.');
        }

        $provider = $this->callStaticObject(self::OTEL_GLOBALS, 'tracerProvider');
        $tracer = $this->callObject(
            $provider,
            'getTracer',
            [$this->options->otelServiceName, $this->options->otelServiceVersion],
        );
        $propagator = $this->callStaticObject(self::OTEL_GLOBALS, 'propagator');
        $context = $this->call($propagator, 'extract', [$this->headersToCarrier($req)]);
        $builder = $this->callObject($tracer, 'spanBuilder', [$this->buildSpanName($req)]);
        $builder = $this->callObject($builder, 'setParent', [$context]);
        $builder = $this->callObject($builder, 'setSpanKind', [$this->otelSpanKindServer()]);
        $builder = $this->callObject($builder, 'setStartTimestamp', [$startNs]);

        return $this->callObject($builder, 'startSpan');
    }
}
