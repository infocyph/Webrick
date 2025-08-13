<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TelemetryMiddleware (observability)
 *
 * • Logs one line per request (IP, method, path, status, bytes, duration, request-id).
 * • Adds X-Response-Time and Server-Timing (app;dur=...).
 * • Emits a stable request ID (header + request attribute) for correlation.
 * • Optionally injects Network Error Logging (NEL) + Report-To (once; won't overwrite).
 *
 * Recommended order:
 *   GatewayHardening → ErrorHandler → Telemetry → (rest)
 */
final class TelemetryMiddleware
{
    public function __construct(
        private LoggerInterface $log = new NullLogger(),
        private bool $addXResponseTime = true,
        private bool $addServerTiming = true,

        // Request ID
        private bool $emitRequestId = true,
        private string $requestIdHeader = 'X-Request-Id',
        private bool $respectExistingRequestId = true,

        // NEL (null group disables NEL entirely)
        private ?string $nelGroup = null,
        private ?string $nelEndpoint = null,  // absolute URL for reports
        private int $nelTtlSeconds = 86400,
        private bool $nelIncludeSubdomains = true,
        private bool $nelCollectSuccesses = false
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        $start = hrtime(true);

        // Correlation ID (propagate if present)
        $requestId = $this->deriveRequestId($req);
        if ($this->emitRequestId && $requestId !== null) {
            $req = $req->withAttribute('request_id', $requestId);
        }

        $resp = $next($req);

        $durMs = (hrtime(true) - $start) / 1e6;

        /* ---------- headers ---------- */

        if ($this->addXResponseTime) {
            $resp = $resp->withHeader('X-Response-Time', sprintf('%.1fms', $durMs));
        }

        if ($this->addServerTiming) {
            $metric = sprintf('app;dur=%.1f', $durMs);
            if (method_exists($resp, 'withSmartHeader')) {
                $resp = $resp->withSmartHeader('Server-Timing', $metric);
            } else {
                // append-friendly fallback if header already present
                $existing = $resp->getHeaderLine('Server-Timing');
                $resp = $resp->withHeader(
                    'Server-Timing',
                    $existing === '' ? $metric : ($existing . ', ' . $metric),
                );
            }
        }

        // Request ID back to client (don’t overwrite)
        if ($this->emitRequestId && $requestId !== null && !$resp->hasHeader($this->requestIdHeader)) {
            $resp = $resp->withHeader($this->requestIdHeader, $requestId);
        }

        // NEL + Report-To (optional, don’t clobber if app already set them)
        if ($this->nelGroup && $this->nelEndpoint) {
            $nel = [
                'group' => $this->nelGroup,
                'max_age' => $this->nelTtlSeconds,
                'include_subdomains' => $this->nelIncludeSubdomains,
                'success_fraction' => $this->nelCollectSuccesses ? 1.0 : 0.0,
                'failure_fraction' => 1.0,
            ];
            $reportTo = [
                'group' => $this->nelGroup,
                'max_age' => $this->nelTtlSeconds,
                'endpoints' => [['url' => $this->nelEndpoint]],
            ];

            if (!$resp->hasHeader('NEL')) {
                $resp = $resp->withHeader('NEL', json_encode($nel, JSON_THROW_ON_ERROR));
            }
            if (!$resp->hasHeader('Report-To')) {
                $resp = $resp->withHeader('Report-To', json_encode($reportTo, JSON_THROW_ON_ERROR));
            }
        }

        /* ---------- logging ---------- */

        $ip = $req->getAttribute('client_ip')
            ?? $req->getServerParams()['REMOTE_ADDR']
            ?? '-';
        $fromProxy = $req->getAttribute('is_trusted_proxy') ? 'proxy' : 'direct';
        $method = $req->getMethod();
        $uri = $req->getUri()->getPath();
        $code = $resp->getStatusCode();

        $lenHeader = $resp->getHeaderLine('Content-Length');
        $len = $lenHeader !== '' ? $lenHeader : ($resp->getBody()->getSize() ?? '-');

        $this->log->info(sprintf(
            '%s (%s) "%s %s" %d %s %.1fms%s',
            $ip,
            $fromProxy,
            $method,
            $uri,
            $code,
            (string)$len,
            $durMs,
            $requestId ? " id={$requestId}" : ''
        ));

        return $resp;
    }

    /* ───────────────────────── helpers ───────────────────────── */

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
            // 16 bytes → 32 hex chars; short, good entropy, log-friendly
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('', true));
        }
    }
}
