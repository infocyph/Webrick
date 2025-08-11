<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TelemetryMiddleware
 *
 * • Logs one line per request (IP, method, path, status, bytes, duration).
 * • Adds X-Response-Time and Server-Timing headers.
 * • Optionally exposes timing to the browser (Timing-Allow-Origin).
 * • Optionally injects Network Error Logging (NEL) + Report-To.
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
        private ?string $timingAllowOrigin = null, // e.g. '*' or 'https://app.example.com'
        // NEL options (null group disables NEL entirely)
        private ?string $nelGroup = null,
        private ?string $nelEndpoint = null,       // absolute URL for reports
        private int $nelTtlSeconds = 86400,
        private bool $nelIncludeSubdomains = true,
        private bool $nelCollectSuccesses = false
    ) {
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $start = hrtime(true);

        // Proceed downstream
        $resp = $next($req);

        $durMs = (hrtime(true) - $start) / 1e6;

        // -------- headers --------
        if ($this->addXResponseTime) {
            $resp = $resp->withHeader('X-Response-Time', sprintf('%.1fms', $durMs));
        }

        if ($this->addServerTiming) {
            // append-friendly if your Response supports withSmartHeader()
            if (method_exists($resp, 'withSmartHeader')) {
                $resp = $resp->withSmartHeader('Server-Timing', sprintf('app;dur=%.1f', $durMs));
            } else {
                $resp = $resp->withHeader('Server-Timing', sprintf('app;dur=%.1f', $durMs));
            }
            if ($this->timingAllowOrigin !== null && $this->timingAllowOrigin !== '') {
                $resp = $resp->withHeader('Timing-Allow-Origin', $this->timingAllowOrigin);
            }
        }

        // -------- NEL + Report-To (optional) --------
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
            $resp = $resp
                ->withHeader('NEL', json_encode($nel, JSON_THROW_ON_ERROR))
                ->withHeader('Report-To', json_encode($reportTo, JSON_THROW_ON_ERROR));
        }

        // -------- logging --------
        $ip = $req->getAttribute('client_ip')
            ?? $req->getServerParams()['REMOTE_ADDR']
            ?? '-';
        $fromProxy = $req->getAttribute('is_trusted_proxy') ? 'proxy' : 'direct';
        $method = $req->getMethod();
        $uri = $req->getUri()->getPath();
        $code = $resp->getStatusCode();
        $len = $resp->getBody()->getSize() ?? '-';

        $this->log->info(
            sprintf(
                '%s (%s) "%s %s" %d %s %.1fms',
                $ip,
                $fromProxy,
                $method,
                $uri,
                $code,
                $len,
                $durMs
            )
        );

        return $resp;
    }
}
