<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\OpenTelemetryHandler;
use Infocyph\Webrick\Support\TelemetryOptions;
use Infocyph\Webrick\Support\TelemetrySupport;
use Infocyph\Webrick\Support\TraceContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Telemetry middleware with W3C Trace Context and optional OpenTelemetry integration. */
final readonly class TelemetryMiddleware
{
    private bool $otelAvailable;

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
        private bool $respectIncomingTraceparent = true,
        private bool $emitTraceparentHeader = false,
        private bool $enableOtelIntegration = false,
        private string $otelServiceName = 'webrick-app',
        private string $otelServiceVersion = '1.0.0',
    ) {
        $this->otelAvailable = $this->enableOtelIntegration
            && class_exists('OpenTelemetry\\API\\Globals')
            && class_exists('OpenTelemetry\\API\\Trace\\SpanKind');
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        if ($this->otelAvailable) {
            return $this->delegateToOtel($req, $next);
        }

        return $this->handleMinimal($req, $next);
    }

    public static function fromOptions(TelemetryOptions $options): self
    {
        return new self(...$options->toMiddlewareConstructorArgs());
    }

    public function options(): TelemetryOptions
    {
        return new TelemetryOptions(
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
            respectIncomingTraceparent: $this->respectIncomingTraceparent,
            emitTraceparentHeader: $this->emitTraceparentHeader,
            enableOtelIntegration: $this->enableOtelIntegration,
            otelServiceName: $this->otelServiceName,
            otelServiceVersion: $this->otelServiceVersion,
        );
    }

    private static function buildTraceParent(string $traceId, string $spanId, string $flags = '01'): string
    {
        return '00-' . strtolower($traceId) . '-' . strtolower($spanId) . '-' . strtolower($flags);
    }

    private static function generateSpanId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return str_pad(substr(str_replace('.', '', uniqid('', true)), 0, 16), 16, '0');
        }
    }

    private static function generateTraceId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return str_pad(substr(str_replace('.', '', uniqid('', true)), 0, 32), 32, '0');
        }
    }

    private static function isValidFlags(string $hex): bool
    {
        return preg_match('/\A[0-9a-f]{2}\z/iD', $hex) === 1;
    }

    private static function isValidSpanId(string $hex): bool
    {
        return preg_match('/\A[0-9a-f]{16}\z/iD', $hex) === 1 && $hex !== str_repeat('0', 16);
    }

    private static function isValidTraceId(string $hex): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/iD', $hex) === 1 && $hex !== str_repeat('0', 32);
    }

    /** @param array{trace_id:string,parent_span_id:string,flags:string,tracestate:string,span_id:string} $trace */
    private function addCorrelationHeaders(Response $resp, array $trace, ?string $requestId): Response
    {
        $resp = TelemetrySupport::addCorrelationHeaders(
            $resp,
            $this->emitRequestId,
            $this->requestIdHeader,
            $requestId,
            $this->emitTraceIdHeader,
            $this->traceIdHeader,
            $trace['trace_id'],
        );

        if ($this->emitTraceparentHeader && !$resp->hasHeader('traceparent')) {
            $resp = $resp->withHeader(
                'traceparent',
                self::buildTraceParent($trace['trace_id'], $trace['span_id'], $trace['flags']),
            );
            if ($trace['tracestate'] !== '' && !$resp->hasHeader('tracestate')) {
                $resp = $resp->withHeader('tracestate', $trace['tracestate']);
            }
        }

        return $resp;
    }

    private function addTimingHeaders(Response $resp, float $durMs): Response
    {
        return TelemetrySupport::addTimingHeaders($resp, $this->addXResponseTime, $this->addServerTiming, $durMs);
    }

    private function applyNelHeaders(Response $resp): Response
    {
        return TelemetrySupport::applyNelHeaders(
            $resp,
            $this->nelGroup,
            $this->nelEndpoint,
            $this->nelTtlSeconds,
            $this->nelIncludeSubdomains,
            $this->nelCollectSuccesses,
        );
    }

    /** @param Closure(Request):Response $next */
    private function delegateToOtel(Request $req, Closure $next): Response
    {
        return new OpenTelemetryHandler($this->options())->handle($req, $next);
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

    /** @return array{0:string,1:string,2:string,3:string} */
    private function extractTraceContext(Request $req): array
    {
        $traceparent = trim($req->getHeaderLine('traceparent'));
        $tracestate = trim($req->getHeaderLine('tracestate'));

        if ($this->respectIncomingTraceparent && $traceparent !== '') {
            $parts = explode('-', $traceparent);
            if (count($parts) === 4) {
                [$version, $traceId, $spanId, $flags] = $parts;
                if (
                    strtolower($version) === '00'
                    && self::isValidTraceId($traceId)
                    && self::isValidSpanId($spanId)
                    && self::isValidFlags($flags)
                ) {
                    return [strtolower($traceId), strtolower($spanId), strtolower($flags), $tracestate];
                }
            }
        }

        // Tracestate is meaningful only together with an accepted traceparent.
        return [self::generateTraceId(), '0000000000000000', '01', ''];
    }

    /** @param Closure(Request):Response $next */
    private function handleMinimal(Request $req, Closure $next): Response
    {
        $startNs = hrtime(true);
        [$req, $trace, $requestId] = $this->prepareContext($req);
        $req = TraceContext::attach($req, false);

        $resp = $next($req);
        $durMs = (hrtime(true) - $startNs) / 1e6;
        $resp = $this->addTimingHeaders($resp, $durMs);
        $resp = $this->addCorrelationHeaders($resp, $trace, $requestId);
        $resp = $this->applyNelHeaders($resp);
        $this->logAccess($req, $resp, $durMs, $trace['span_id'], $trace['trace_id'], $requestId);

        return $resp;
    }

    private function logAccess(
        Request $req,
        Response $resp,
        float $durMs,
        string $spanId,
        string $traceId,
        ?string $requestId,
    ): void {
        TelemetrySupport::logAccess(
            $this->log,
            $req,
            $resp,
            $durMs,
            $spanId,
            $traceId,
            $requestId,
            'w3c',
        );
    }

    /** @return array{0:Request,1:array{trace_id:string,parent_span_id:string,flags:string,tracestate:string,span_id:string},2:?string} */
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

        $requestId = $this->deriveRequestId($req);
        $attributes = [
            'trace.trace_id' => $traceId,
            'trace.parent_span_id' => $parentSpanId,
            'trace.span_id' => $spanId,
            'trace.flags' => $flags,
            'trace.tracestate' => $tracestate,
        ];
        if ($this->emitRequestId && $requestId !== null) {
            $attributes['request_id'] = $requestId;
        }

        return [$req->withAttributes($attributes), $trace, $requestId];
    }
}
