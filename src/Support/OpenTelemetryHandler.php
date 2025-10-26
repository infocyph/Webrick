<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OpenTelemetry handler for full span management and observability.
 *
 * This class is only loaded when OpenTelemetry SDK is available.
 * It handles automatic span creation, attribute enrichment, exception recording,
 * and span export to observability backends (Jaeger, Zipkin, OTLP).
 *
 * Features:
 * - Automatic span creation with semantic conventions
 * - HTTP span attributes (method, URL, status, etc.)
 * - Network attributes (client IP, port)
 * - Custom attributes (route name, user ID)
 * - Exception recording with stack traces
 * - Distributed tracing context propagation
 * - Response timing headers
 * - Correlation headers (trace ID, request ID)
 * - Network Error Logging (NEL) support
 *
 * @internal Used by TelemetryMiddleware when OTel SDK is detected
 * @see https://opentelemetry.io/docs/specs/semconv/http/
 * @see https://www.w3.org/TR/trace-context/
 */
final readonly class OpenTelemetryHandler
{
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
    ) {
    }

    /**
     * Handle request with full OpenTelemetry span management.
     */
    public function handle(Request $req, Closure $next): Response
    {
        $startNs = hrtime(true);

        // Get tracer from global provider
        $tracer = Globals::tracerProvider()
            ->getTracer($this->otelServiceName, $this->otelServiceVersion);

        // Extract context from incoming request headers
        $context = Globals::propagator()->extract($this->headersToCarrier($req));

        // Build span name (prefer route name over path)
        $spanName = $this->buildSpanName($req);

        // Start server span
        $span = $tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setStartTimestamp($startNs)
            ->startSpan();

        // Activate context (makes span available via Context API)
        $scope = $span->activate();

        try {
            // Add HTTP semantic convention attributes
            $this->addSpanAttributes($span, $req);

            // Extract trace context from OTel span
            $traceId = $span->getContext()->getTraceId();
            $spanId = $span->getContext()->getSpanId();
            $requestId = $this->deriveRequestId($req);

            // Enrich request with trace context for application use
            $req = $req
                ->withAttribute('trace.trace_id', $traceId)
                ->withAttribute('trace.span_id', $spanId)
                ->withAttribute('request_id', $requestId);

            // Initialize global trace context for application-wide access
            TraceContext::initialize($req, true);

            // Execute request
            $resp = $next($req);

            // Add response attributes to span
            $this->addResponseAttributes($span, $resp);

            // Set span status based on HTTP status code
            $this->setSpanStatus($span, $resp->getStatusCode());

            // Compute duration
            $durMs = (hrtime(true) - $startNs) / 1e6;

            // Add timing headers
            $resp = $this->addTimingHeaders($resp, $durMs);

            // Add correlation headers
            $resp = $this->addCorrelationHeaders($resp, $traceId, $spanId, $requestId);

            // Apply NEL headers if configured
            $resp = $this->applyNelHeaders($resp);

            // Access log
            $this->logAccess($req, $resp, $durMs, $spanId, $traceId, $requestId);

            return $resp;

        } catch (\Throwable $e) {
            // Record exception in span (includes stack trace)
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            // Re-throw to allow application error handlers to process
            throw $e;
        } finally {
            // End span and detach scope
            $span->end();
            $scope->detach();

            // Clean up trace context
            TraceContext::clear();
        }
    }

    /**
     * Add correlation headers (trace ID, request ID) to response.
     */
    private function addCorrelationHeaders(
        Response $resp,
        string $traceId,
        string $spanId,
        ?string $requestId
    ): Response {
        if ($this->emitRequestId && $requestId !== null && !$resp->hasHeader($this->requestIdHeader)) {
            $resp = $resp->withHeader($this->requestIdHeader, $requestId);
        }

        if ($this->emitTraceIdHeader && !$resp->hasHeader($this->traceIdHeader)) {
            $resp = $resp->withHeader($this->traceIdHeader, $traceId);
        }

        return $resp;
    }

    /**
     * Add custom/application-specific span attributes.
     */
    private function addCustomAttributes(object $span, Request $req): void
    {
        // Route name (for better span grouping)
        $routeName = $req->getAttribute('route.name');
        if ($routeName) {
            $span->setAttribute('http.route', $routeName);
        }

        // Authenticated user ID
        $userId = $req->getAttribute('auth.user_id');
        if ($userId) {
            $span->setAttribute('enduser.id', (string) $userId);
        }

        // User role/scope (if available)
        $userRole = $req->getAttribute('auth.role');
        if ($userRole) {
            $span->setAttribute('enduser.role', (string) $userRole);
        }

        // Client type (web, mobile, api, etc.)
        $clientType = $req->getAttribute('client.type');
        if ($clientType) {
            $span->setAttribute('client.type', (string) $clientType);
        }

        // API version (if versioned API)
        $apiVersion = $req->getAttribute('api.version');
        if ($apiVersion) {
            $span->setAttribute('api.version', (string) $apiVersion);
        }

        // Trusted proxy indicator
        $isTrustedProxy = $req->getAttribute('is_trusted_proxy');
        if ($isTrustedProxy !== null) {
            $span->setAttribute('http.client.is_trusted_proxy', (bool) $isTrustedProxy);
        }
    }

    /**
     * Add network-related span attributes.
     */
    private function addNetworkAttributes(object $span, Request $req): void
    {
        // Client IP address
        $clientIp = $req->getAttribute('client_ip')
            ?? $req->getServerParams()['REMOTE_ADDR']
            ?? null;

        if ($clientIp) {
            $span->setAttribute('net.peer.ip', $clientIp);
        }

        // Server port
        $serverPort = $req->getUri()->getPort();
        if ($serverPort !== null) {
            $span->setAttribute('net.host.port', $serverPort);
        }

        // Protocol version
        $protocolVersion = $req->getProtocolVersion();
        if ($protocolVersion !== '') {
            $span->setAttribute('http.flavor', $protocolVersion);
        }
    }

    /**
     * Add response-related span attributes.
     */
    private function addResponseAttributes(object $span, Response $resp): void
    {
        // HTTP status code
        $span->setAttribute('http.status_code', $resp->getStatusCode());

        // Response content length
        $contentLength = $resp->getBody()->getSize();
        if ($contentLength !== null) {
            $span->setAttribute('http.response_content_length', $contentLength);
        }

        // Response content type
        $contentType = $resp->getHeaderLine('Content-Type');
        if ($contentType !== '') {
            $span->setAttribute('http.response_content_type', $contentType);
        }
    }

    /**
     * Add OpenTelemetry span attributes following semantic conventions.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/http/http-spans/
     */
    private function addSpanAttributes(object $span, Request $req): void
    {
        // HTTP attributes (semantic conventions)
        $span->setAttribute('http.method', $req->getMethod());
        $span->setAttribute('http.target', $req->getPath());
        $span->setAttribute('http.scheme', $req->getUri()->getScheme());
        $span->setAttribute('http.host', $req->getUri()->getHost());

        // Full URL (optional, can contain sensitive data)
        $url = (string) $req->getUri();
        if ($url !== '') {
            $span->setAttribute('http.url', $url);
        }

        // User agent
        $userAgent = $req->getHeaderLine('User-Agent');
        if ($userAgent !== '') {
            $span->setAttribute('http.user_agent', $userAgent);
        }

        // Request content length
        $contentLength = $req->getHeaderLine('Content-Length');
        if ($contentLength !== '' && is_numeric($contentLength)) {
            $span->setAttribute('http.request_content_length', (int) $contentLength);
        }

        // Network attributes
        $this->addNetworkAttributes($span, $req);

        // Custom/application attributes
        $this->addCustomAttributes($span, $req);

        // Server attributes
        $span->setAttribute('http.server_name', $this->otelServiceName);
    }

    /**
     * Add timing headers (X-Response-Time, Server-Timing).
     */
    private function addTimingHeaders(Response $resp, float $durMs): Response
    {
        if ($this->addXResponseTime) {
            $resp = $resp->withHeader('X-Response-Time', sprintf('%.1fms', $durMs));
        }

        if ($this->addServerTiming) {
            $metric = sprintf('app;dur=%.1f', $durMs);
            if (method_exists($resp, 'withSmartHeader')) {
                $resp = $resp->withSmartHeader('Server-Timing', $metric);
            } else {
                $existing = $resp->getHeaderLine('Server-Timing');
                $resp = $resp->withHeader('Server-Timing', $existing === '' ? $metric : ($existing . ', ' . $metric));
            }
        }

        return $resp;
    }

    /**
     * Apply Network Error Logging (NEL) headers.
     */
    private function applyNelHeaders(Response $resp): Response
    {
        if (!($this->nelGroup && $this->nelEndpoint)) {
            return $resp;
        }

        if (!$resp->hasHeader('NEL')) {
            $nel = [
                'group' => $this->nelGroup,
                'max_age' => $this->nelTtlSeconds,
                'include_subdomains' => $this->nelIncludeSubdomains,
                'success_fraction' => $this->nelCollectSuccesses ? 1.0 : 0.0,
                'failure_fraction' => 1.0,
            ];
            $resp = $resp->withHeader('NEL', json_encode($nel, JSON_THROW_ON_ERROR));
        }

        if (!$resp->hasHeader('Report-To')) {
            $reportTo = [
                'group' => $this->nelGroup,
                'max_age' => $this->nelTtlSeconds,
                'endpoints' => [['url' => $this->nelEndpoint]],
            ];
            $resp = $resp->withHeader('Report-To', json_encode($reportTo, JSON_THROW_ON_ERROR));
        }

        return $resp;
    }

    /**
     * Build span name from request (prefer route name over path).
     */
    private function buildSpanName(Request $req): string
    {
        $method = $req->getMethod();
        $routeName = $req->getAttribute('route.name');

        if ($routeName) {
            return $method . ' ' . $routeName;
        }

        return $method . ' ' . $req->getPath();
    }

    /**
     * Derive or generate request ID.
     */
    private function deriveRequestId(Request $req): ?string
    {
        if (!$this->emitRequestId) {
            return null;
        }

        $incoming = trim($req->getHeaderLine($this->requestIdHeader));
        if ($incoming !== '' && $this->respectExistingRequestId) {
            return $incoming;
        }

        try {
            return bin2hex(random_bytes(16)); // 32 hex chars
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('', true));
        }
    }

    /**
     * Convert request headers to carrier format for OTel propagator.
     */
    private function headersToCarrier(Request $req): array
    {
        $carrier = [];
        foreach ($req->getHeaders() as $name => $values) {
            // Propagators expect lowercase header names
            $carrier[strtolower($name)] = $values[0] ?? '';
        }
        return $carrier;
    }

    /**
     * Log access with trace correlation.
     */
    private function logAccess(
        Request $req,
        Response $resp,
        float $durMs,
        string $spanId,
        string $traceId,
        ?string $requestId,
    ): void {
        $ip = $req->getAttribute('client_ip') ?? $req->getServerParams()['REMOTE_ADDR'] ?? '-';
        $fromProxy = $req->getAttribute('is_trusted_proxy') ? 'proxy' : 'direct';
        $method = $req->getMethod();
        $path = $req->getUri()->getPath() ?: '/';
        $code = $resp->getStatusCode();
        $lenHeader = $resp->getHeaderLine('Content-Length');
        $len = $lenHeader !== '' ? $lenHeader : ($resp->getBody()->getSize() ?? '-');

        $this->log->info(
            sprintf(
                '%s (%s) "%s %s" %d %s %.1fms%s trace=%s span=%s [otel]',
                $ip,
                $fromProxy,
                $method,
                $path,
                $code,
                (string)$len,
                $durMs,
                $requestId ? " id={$requestId}" : '',
                $traceId,
                $spanId,
            ),
        );
    }

    /**
     * Set span status based on HTTP status code.
     */
    private function setSpanStatus(object $span, int $statusCode): void
    {
        if ($statusCode >= 500) {
            // 5xx = Server error
            $span->setStatus(StatusCode::STATUS_ERROR, 'HTTP ' . $statusCode);
        } elseif ($statusCode >= 400) {
            // 4xx = Client error (not a span error, but useful to track)
            $span->setStatus(StatusCode::STATUS_OK);
            $span->setAttribute('http.status_class', '4xx');
        } else {
            // 2xx, 3xx = Success
            $span->setStatus(StatusCode::STATUS_OK);
        }
    }
}
