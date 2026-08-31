<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Log\LoggerInterface;
use Stringable;

final class TelemetrySupport
{
    public static function addCorrelationHeaders(
        Response $resp,
        bool $emitRequestId,
        string $requestIdHeader,
        ?string $requestId,
        bool $emitTraceIdHeader,
        string $traceIdHeader,
        string $traceId,
    ): Response {
        if ($emitRequestId && $requestId !== null && !$resp->hasHeader($requestIdHeader)) {
            $resp = $resp->withHeader($requestIdHeader, $requestId);
        }

        if ($emitTraceIdHeader && !$resp->hasHeader($traceIdHeader)) {
            $resp = $resp->withHeader($traceIdHeader, $traceId);
        }

        return $resp;
    }

    public static function addTimingHeaders(Response $resp, bool $addXResponseTime, bool $addServerTiming, float $durMs): Response
    {
        if ($addXResponseTime) {
            $resp = $resp->withHeader('X-Response-Time', sprintf('%.1fms', $durMs));
        }

        if ($addServerTiming) {
            $metric = sprintf('app;dur=%.1f', $durMs);
            $resp = $resp->withSmartHeader('Server-Timing', $metric);
        }

        return $resp;
    }

    public static function applyNelHeaders(
        Response $resp,
        ?string $nelGroup,
        ?string $nelEndpoint,
        int $nelTtlSeconds,
        bool $nelIncludeSubdomains,
        bool $nelCollectSuccesses,
    ): Response {
        if (!($nelGroup && $nelEndpoint)) {
            return $resp;
        }

        if (!$resp->hasHeader('NEL')) {
            $nel = [
                'group' => $nelGroup,
                'max_age' => $nelTtlSeconds,
                'include_subdomains' => $nelIncludeSubdomains,
                'success_fraction' => $nelCollectSuccesses ? 1.0 : 0.0,
                'failure_fraction' => 1.0,
            ];
            $resp = $resp->withHeader('NEL', json_encode($nel, JSON_THROW_ON_ERROR));
        }

        if (!$resp->hasHeader('Report-To')) {
            $reportTo = [
                'group' => $nelGroup,
                'max_age' => $nelTtlSeconds,
                'endpoints' => [['url' => $nelEndpoint]],
            ];
            $resp = $resp->withHeader('Report-To', json_encode($reportTo, JSON_THROW_ON_ERROR));
        }

        return $resp;
    }

    public static function deriveRequestId(
        Request $req,
        bool $emitRequestId,
        string $requestIdHeader,
        bool $respectExistingRequestId,
    ): ?string {
        if (!$emitRequestId) {
            return null;
        }

        if ($respectExistingRequestId) {
            $incoming = self::normalizeRequestId($req->getHeaderLine($requestIdHeader));
            if ($incoming !== null) {
                return $incoming;
            }
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('', true));
        }
    }

    public static function logAccess(
        LoggerInterface $log,
        Request $req,
        Response $resp,
        float $durMs,
        string $spanId,
        string $traceId,
        ?string $requestId,
        string $mode,
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
        $lenOut = self::stringFromMixed($len) ?? '-';

        $log->info(
            sprintf(
                '%s (%s) "%s %s" %d %s %.1fms%s trace=%s span=%s [%s]',
                $ip,
                $fromProxy,
                $method,
                $path,
                $code,
                $lenOut,
                $durMs,
                $requestId !== null ? " id={$requestId}" : '',
                $traceId,
                $spanId,
                $mode,
            ),
        );
    }

    public static function stringFromMixed(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value) || \is_bool($value)) {
            return (string) $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }

    private static function normalizeRequestId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 128) {
            return null;
        }
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $value) !== 1) {
            return null;
        }

        return $value;
    }
}
