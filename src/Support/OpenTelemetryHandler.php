<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Closure;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final readonly class OpenTelemetryHandler
{
    private const string OTEL_GLOBALS = 'OpenTelemetry\\API\\Globals';

    private const string OTEL_SPAN_KIND_SERVER = 'OpenTelemetry\\API\\Trace\\SpanKind::KIND_SERVER';

    private const string OTEL_STATUS_ERROR = 'OpenTelemetry\\API\\Trace\\StatusCode::STATUS_ERROR';

    private const string OTEL_STATUS_OK = 'OpenTelemetry\\API\\Trace\\StatusCode::STATUS_OK';

    public function __construct(
        private LoggerInterface $log = new NullLogger(),
        private bool $addXResponseTime = true,
        private bool $addServerTiming = true,
        private bool $emitRequestId = true,
        private string $requestIdHeader = 'X-Request-Id',
        private bool $respectExistingRequestId = true,
        private ?string $nelGroup = null,
        private ?string $nelEndpoint = null,
        private int $nelTtlSeconds = 86400,
        private bool $nelIncludeSubdomains = true,
        private bool $nelCollectSuccesses = false,
        private bool $emitTraceIdHeader = true,
        private string $traceIdHeader = 'Trace-Id',
        private string $otelServiceName = 'webrick-app',
        private string $otelServiceVersion = '1.0.0',
    ) {}

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

            TraceContext::initialize($req, true);

            $resp = $next($req);

            $this->addResponseAttributes($span, $resp);
            $this->setSpanStatus($span, $resp->getStatusCode());

            $durMs = (hrtime(true) - $startNs) / 1e6;
            $resp = TelemetrySupport::addTimingHeaders($resp, $this->addXResponseTime, $this->addServerTiming, $durMs);
            $resp = $this->addCorrelationHeaders($resp, $traceId, $requestId);
            $resp = TelemetrySupport::applyNelHeaders(
                $resp,
                $this->nelGroup,
                $this->nelEndpoint,
                $this->nelTtlSeconds,
                $this->nelIncludeSubdomains,
                $this->nelCollectSuccesses,
            );
            TelemetrySupport::logAccess($this->log, $req, $resp, $durMs, $spanId, $traceId, $requestId, 'otel');

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
            TraceContext::clear();
        }
    }

    private function addCorrelationHeaders(Response $resp, string $traceId, ?string $requestId): Response
    {
        return TelemetrySupport::addCorrelationHeaders(
            $resp,
            $this->emitRequestId,
            $this->requestIdHeader,
            $requestId,
            $this->emitTraceIdHeader,
            $this->traceIdHeader,
            $traceId,
        );
    }

    private function addCustomAttributes(object $span, Request $req): void
    {
        $routeName = $req->getAttribute('route.name');
        if (\is_string($routeName) && $routeName !== '') {
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

        $isTrustedProxy = $req->getAttribute('is_trusted_proxy');
        if (\is_bool($isTrustedProxy)) {
            $this->setSpanAttribute($span, 'http.client.is_trusted_proxy', $isTrustedProxy);
        }
    }

    private function addNetworkAttributes(object $span, Request $req): void
    {
        $clientIp = TelemetrySupport::stringFromMixed($req->getAttribute('client_ip'));
        if ($clientIp === null) {
            $remote = $req->getServerParams()['REMOTE_ADDR'] ?? null;
            $clientIp = \is_string($remote) && $remote !== '' ? $remote : null;
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
        $this->setSpanAttribute($span, 'http.server_name', $this->otelServiceName);
    }

    private function buildSpanName(Request $req): string
    {
        $method = $req->getMethod();
        $routeName = $req->getAttribute('route.name');

        if (\is_string($routeName) && $routeName !== '') {
            return $method . ' ' . $routeName;
        }

        return $method . ' ' . ($req->getUri()->getPath() ?: '/');
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
        if (!\is_object($result)) {
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
        if (!\is_object($result)) {
            throw new RuntimeException(sprintf('Static method %s::%s() did not return an object.', $class, $method));
        }

        return $result;
    }

    private function deriveRequestId(Request $req): ?string
    {
        return TelemetrySupport::deriveRequestId(
            $req,
            $this->emitRequestId,
            $this->requestIdHeader,
            $this->respectExistingRequestId,
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function extractTraceContext(object $span): array
    {
        $context = $this->callObject($span, 'getContext');
        $traceId = TelemetrySupport::stringFromMixed($this->call($context, 'getTraceId')) ?? '';
        $spanId = TelemetrySupport::stringFromMixed($this->call($context, 'getSpanId')) ?? '';

        return [$traceId, $spanId];
    }

    /**
     * @return array<string,string>
     */
    private function headersToCarrier(Request $req): array
    {
        $carrier = [];
        foreach ($req->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);
            $carrier[$lower] = $values[0] ?? '';
        }

        return $carrier;
    }

    private function otelIntConstant(string $name, int $fallback, string $label): int
    {
        if (!defined($name)) {
            return $fallback;
        }

        $value = constant($name);
        if (!\is_int($value)) {
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
        $tracer = $this->callObject($provider, 'getTracer', [$this->otelServiceName, $this->otelServiceVersion]);

        $propagator = $this->callStaticObject(self::OTEL_GLOBALS, 'propagator');
        $context = $this->call($propagator, 'extract', [$this->headersToCarrier($req)]);

        $builder = $this->callObject($tracer, 'spanBuilder', [$this->buildSpanName($req)]);
        $builder = $this->callObject($builder, 'setParent', [$context]);
        $builder = $this->callObject($builder, 'setSpanKind', [$this->otelSpanKindServer()]);
        $builder = $this->callObject($builder, 'setStartTimestamp', [$startNs]);

        return $this->callObject($builder, 'startSpan');
    }
}
