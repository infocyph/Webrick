<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\OpenTelemetryHandler;
use Infocyph\Webrick\Support\TraceContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Telemetry middleware with W3C Trace Context and optional OpenTelemetry integration.
 *
 * Minimal mode (default - zero dependencies):
 * - W3C Trace Context propagation (traceparent/tracestate)
 * - Request ID generation and correlation
 * - Response timing headers (X-Response-Time, Server-Timing)
 * - Access logging with trace correlation
 * - Global trace context via TraceContext helper
 *
 * OpenTelemetry mode (auto-enabled when SDK is installed):
 * - Delegates to OpenTelemetryHandler for full span management
 * - Automatic span creation with semantic conventions
 * - Span export to Jaeger, Zipkin, OTLP collectors
 * - Exception recording in spans with stack traces
 * - Distributed tracing UI visibility
 *
 * No configuration needed - automatically detects and uses OpenTelemetry SDK if available.
 *
 * @see https://www.w3.org/TR/trace-context/
 * @see https://opentelemetry.io/docs/specs/semconv/http/
 */
final readonly class TelemetryMiddleware
{
    private bool $otelAvailable;

    public function __construct(
        private LoggerInterface $log = new NullLogger(),
        private bool $addXResponseTime = true,
        private bool $addServerTiming = true,

        // Request ID
        private bool $emitRequestId = true,
        private string $requestIdHeader = 'X-Request-Id',
        private bool $respectExistingRequestId = true,

        // NEL (Network Error Logging)
        private ?string $nelGroup = null,
        private ?string $nelEndpoint = null,
        private int $nelTtlSeconds = 86400,
        private bool $nelIncludeSubdomains = true,
        private bool $nelCollectSuccesses = false,

        // Tracing (W3C + optional OTel)
        private bool $emitTraceIdHeader = true,
        private string $traceIdHeader = 'Trace-Id',
        private bool $respectIncomingTraceparent = true,
        private bool $emitTraceparentHeader = false,

        // OpenTelemetry (auto-detected)
        private bool $enableOtelIntegration = false,
        private string $otelServiceName = 'webrick-app',
        private string $otelServiceVersion = '1.0.0',
    ) {
        // Auto-detect OpenTelemetry SDK availability
        $this->otelAvailable = $this->enableOtelIntegration
            && class_exists('OpenTelemetry\\API\\Globals')
            && class_exists('OpenTelemetry\\API\\Trace\\SpanKind');
    }

    /**
     * @param Closure(Request):Response $next
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        if ($this->otelAvailable) {
            return $this->delegateToOtel($req, $next);
        }

        return $this->handleMinimal($req, $next);
    }

    /* ======================= Static helpers ======================= */

    /**
     * Build W3C traceparent header.
     */
    private static function buildTraceParent(string $traceId, string $spanId, string $flags = '01'): string
    {
        return '00-' . strtolower($traceId) . '-' . strtolower($spanId) . '-' . strtolower($flags);
    }

    /**
     * Generate random span ID (16 hex chars).
     */
    private static function generateSpanId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return str_pad(substr(str_replace('.', '', uniqid('', true)), 0, 16), 16, '0');
        }
    }

    /**
     * Generate random trace ID (32 hex chars).
     */
    private static function generateTraceId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return str_pad(substr(str_replace('.', '', uniqid('', true)), 0, 32), 32, '0');
        }
    }

    /**
     * Validate W3C trace flags (2 hex chars).
     */
    private static function isValidFlags(string $hex): bool
    {
        return \strlen($hex) === 2 && ctype_xdigit($hex);
    }

    /**
     * Validate W3C span ID (16 hex chars, non-zero).
     */
    private static function isValidSpanId(string $hex): bool
    {
        return \strlen($hex) === 16 && ctype_xdigit($hex) && $hex !== str_repeat('0', 16);
    }

    /**
     * Validate W3C trace ID (32 hex chars, non-zero).
     */
    private static function isValidTraceId(string $hex): bool
    {
        return \strlen($hex) === 32 && ctype_xdigit($hex) && $hex !== str_repeat('0', 32);
    }

    /**
     * Add Request-Id, Trace-Id, and optional traceparent/tracestate to the response.
     *
     * @param array{trace_id:string,parent_span_id:string,flags:string,tracestate:string,span_id:string} $trace
     */
    private function addCorrelationHeaders(Response $resp, array $trace, ?string $requestId): Response
    {
        if ($this->emitRequestId && $requestId !== null && !$resp->hasHeader($this->requestIdHeader)) {
            $resp = $resp->withHeader($this->requestIdHeader, $requestId);
        }

        if ($this->emitTraceIdHeader && !$resp->hasHeader($this->traceIdHeader)) {
            $resp = $resp->withHeader($this->traceIdHeader, $trace['trace_id']);
        }

        if ($this->emitTraceparentHeader && !$resp->hasHeader('traceparent')) {
            $resp = $resp->withHeader(
                'traceparent',
                self::buildTraceParent(
                    $trace['trace_id'],
                    $trace['span_id'],
                    $trace['flags'],
                ),
            );
            if ($trace['tracestate'] !== '' && !$resp->hasHeader('tracestate')) {
                $resp = $resp->withHeader('tracestate', $trace['tracestate']);
            }
        }

        return $resp;
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
            $resp = $resp->withSmartHeader('Server-Timing', $metric);
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
     * Delegate to OpenTelemetryHandler for full OTel integration.
     *
     * @param Closure(Request):Response $next
     */
    private function delegateToOtel(Request $req, Closure $next): Response
    {
        $handler = new OpenTelemetryHandler(
            log: $this->log,
            addXResponseTime: $this->addXResponseTime,
            addServerTiming: $this->addServerTiming,
            emitRequestId: $this->emitRequestId,
            requestIdHeader: $this->requestIdHeader,
            respectExistingRequestId: $this->respectExistingRequestId,
            nelGroup: $this->nelGroup,
            nelEndpoint: $this->nelEndpoint,
            nelTtlSeconds: $this->nelTtlSeconds,
            nelIncludeSubdomains: $this->nelIncludeSubdomains,
            nelCollectSuccesses: $this->nelCollectSuccesses,
            emitTraceIdHeader: $this->emitTraceIdHeader,
            traceIdHeader: $this->traceIdHeader,
            otelServiceName: $this->otelServiceName,
            otelServiceVersion: $this->otelServiceVersion,
        );

        return $handler->handle($req, $next);
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
     * Extract W3C Trace Context from incoming request.
     *
     * @return array{0:string,1:string,2:string,3:string} [traceId, parentSpanId, flags, tracestate]
     */
    private function extractTraceContext(Request $req): array
    {
        $tp = trim($req->getHeaderLine('traceparent'));
        $ts = trim($req->getHeaderLine('tracestate'));

        if ($this->respectIncomingTraceparent && $tp !== '') {
            // Format: version-traceid-spanid-flags (lowercase hex)
            $parts = explode('-', $tp);
            if (\count($parts) === 4) {
                [$ver, $tid, $sid, $flg] = $parts;
                $ver = strtolower($ver);
                if ($ver === '00' && self::isValidTraceId($tid) && self::isValidSpanId($sid) && self::isValidFlags(
                    $flg,
                )) {
                    return [strtolower($tid), strtolower($sid), strtolower($flg), $ts];
                }
            }
        }

        // New trace, sampled by default (flags 01)
        return [self::generateTraceId(), '0000000000000000', '01', $ts];
    }

    /**
     * Minimal W3C trace context handling (no OTel SDK required).
     *
     * @param Closure(Request):Response $next
     */
    private function handleMinimal(Request $req, Closure $next): Response
    {
        $startNs = hrtime(true);

        // 1) Enrich request with trace context & request id
        [$req, $trace, $requestId] = $this->prepareContext($req);

        // 2) Initialize global trace context for application-wide access
        TraceContext::initialize($req, false);

        try {
            // 3) Execute next middleware/handler
            $resp = $next($req);

            // 4) Compute duration
            $durMs = (hrtime(true) - $startNs) / 1e6;

            // 5) Decorate response (timing, correlation, nel)
            $resp = $this->addTimingHeaders($resp, $durMs);
            $resp = $this->addCorrelationHeaders($resp, $trace, $requestId);
            $resp = $this->applyNelHeaders($resp);

            // 6) Access log
            $this->logAccess($req, $resp, $durMs, $trace['span_id'], $trace['trace_id'], $requestId);

            return $resp;
        } finally {
            // Clean up trace context (important for long-running processes)
            TraceContext::clear();
        }
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
        $clientIp = $req->getAttribute('client_ip');
        $remoteAddr = $req->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = \is_string($clientIp)
            ? $clientIp
            : (\is_string($remoteAddr) ? $remoteAddr : '-');
        $fromProxy = $req->getAttribute('is_trusted_proxy') === true ? 'proxy' : 'direct';
        $method = $req->getMethod();
        $path = $req->getUri()->getPath() ?: '/';
        $code = $resp->getStatusCode();
        $lenHeader = $resp->getHeaderLine('Content-Length');
        $len = $lenHeader !== '' ? $lenHeader : ($resp->getBody()->getSize() ?? '-');

        $this->log->info(
            sprintf(
                '%s (%s) "%s %s" %d %s %.1fms%s trace=%s span=%s [w3c]',
                $ip,
                $fromProxy,
                $method,
                $path,
                $code,
                (string) $len,
                $durMs,
                $requestId ? " id={$requestId}" : '',
                $traceId,
                $spanId,
            ),
        );
    }

    /**
     * Prepare W3C trace context + request id and attach them to the Request.
     *
     * @return array{0:Request,1:array{trace_id:string,parent_span_id:string,flags:string,tracestate:string,span_id:string},2:?string}
     */
    private function prepareContext(Request $req): array
    {
        [$traceId, $parentSpanId, $flags, $tracestate] = $this->extractTraceContext($req);
        $spanId = self::generateSpanId();

        $trace = [
            'trace_id' => $traceId,
            'parent_span_id' => $parentSpanId,
            'flags' => $flags,
            'tracestate' => $tracestate,
            'span_id' => $spanId,
        ];

        $req = $req
            ->withAttribute('trace.trace_id', $traceId)
            ->withAttribute('trace.parent_span_id', $parentSpanId)
            ->withAttribute('trace.span_id', $spanId)
            ->withAttribute('trace.flags', $flags)
            ->withAttribute('trace.tracestate', $tracestate);

        $requestId = $this->deriveRequestId($req);
        if ($this->emitRequestId && $requestId !== null) {
            $req = $req->withAttribute('request_id', $requestId);
        }

        return [$req, $trace, $requestId];
    }
}
