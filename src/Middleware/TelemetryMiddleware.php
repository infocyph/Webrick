<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Telemetry middleware (pure W3C tracecontext, no OTel).
 * Responsibilities kept the same, but __invoke is simplified by delegation.
 */
final readonly class TelemetryMiddleware
{
    public function __construct(
        private LoggerInterface $log = new NullLogger(),
        private bool $addXResponseTime = true,
        private bool $addServerTiming = true,

        // Request ID
        private bool $emitRequestId = true,
        private string $requestIdHeader = 'X-Request-Id',
        private bool $respectExistingRequestId = true,

        // NEL
        private ?string $nelGroup = null,
        private ?string $nelEndpoint = null,
        private int $nelTtlSeconds = 86400,
        private bool $nelIncludeSubdomains = true,
        private bool $nelCollectSuccesses = false,

        // Tracing (pure W3C; no OTel)
        private bool $emitTraceIdHeader = true,
        private string $traceIdHeader = 'Trace-Id',
        private bool $respectIncomingTraceparent = true,
        private bool $emitTraceparentHeader = false,
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $startNs = hrtime(true);

        // 1) Enrich request with trace context & request id
        [$req, $trace, $requestId] = $this->prepareContext($req);

        // 2) Execute next
        $resp = $next($req);

        // 3) Compute duration
        $durMs = (hrtime(true) - $startNs) / 1e6;

        // 4) Decorate response (timing, correlation, nel)
        $resp = $this->addTimingHeaders($resp, $durMs);
        $resp = $this->addCorrelationHeaders($resp, $trace, $requestId);
        $resp = $this->applyNelHeaders($resp);

        // 5) Access log
        $this->logAccess($req, $resp, $durMs, $trace['span_id'], $trace['trace_id'], $requestId);

        return $resp;
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
        return \strlen($hex) === 2 && ctype_xdigit($hex);
    }

    private static function isValidSpanId(string $hex): bool
    {
        return \strlen($hex) === 16 && ctype_xdigit($hex) && $hex !== str_repeat('0', 16);
    }

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

    /* ======================= Utilities ======================= */

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
                '%s (%s) "%s %s" %d %s %.1fms%s trace=%s span=%s',
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

    /* ======================= Core steps ======================= */

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
