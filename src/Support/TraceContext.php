<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Request\Request;

/**
 * Global trace context accessor for correlation across the application.
 *
 * Provides universal access to trace ID, span ID, and request ID throughout
 * the application lifecycle - in controllers, repositories, loggers, cache
 * operations, database queries, external API calls, etc.
 *
 * Features:
 * - Zero-overhead static access to trace context
 * - Works with both minimal (W3C) and OpenTelemetry modes
 * - Automatic fallback between OTel and request attributes
 * - Thread-safe for FPM/CLI (no shared state between requests)
 * - Easy cleanup for long-running processes and tests
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final class TraceContext
{
    /**
     * Whether OpenTelemetry mode is active.
     */
    private static bool $otelAvailable = false;
    /**
     * Current request with trace context attached.
     */
    private static ?Request $request = null;

    /**
     * Prevent instantiation (static class).
     */
    private function __construct()
    {
    }

    /**
     * Clear trace context.
     *
     * Important for:
     * - Long-running processes (workers, daemons)
     * - Test cleanup between test cases
     * - Memory management in high-throughput scenarios
     *
     * Called automatically by TelemetryMiddleware in finally block.
     */
    public static function clear(): void
    {
        self::$request = null;
        self::$otelAvailable = false;
    }

    /**
     * Get all trace context as array.
     *
     * Useful for:
     * - Passing entire context to background jobs
     * - Logging full context in error handlers
     * - Debugging trace propagation issues
     *
     * @return array{trace_id:?string,span_id:?string,parent_span_id:?string,request_id:?string,flags:?string,tracestate:?string}
     */
    public static function getAll(): array
    {
        return [
            'trace_id' => self::getTraceId(),
            'span_id' => self::getSpanId(),
            'parent_span_id' => self::getParentSpanId(),
            'request_id' => self::getRequestId(),
            'flags' => self::getFlags(),
            'tracestate' => self::getTraceState(),
        ];
    }

    /**
     * Get trace flags (2 hex characters).
     *
     * Flags provide additional context about the trace:
     * - 01 = sampled (trace is being recorded)
     * - 00 = not sampled (trace is not being recorded)
     *
     * @return string|null Trace flags or null if not available
     */
    public static function getFlags(): ?string
    {
        return self::$request?->getAttribute('trace.flags');
    }

    /**
     * Get trace context as array for structured logging.
     *
     * Returns only non-null values, suitable for merging into log context.
     *
     * Example:
     * ```php
     * $logger->info('User action', array_merge(
     *     ['action' => 'login', 'user_id' => 42],
     *     TraceContext::getLogArray()
     * ));
     * ```
     *
     * @return array<string,string> Trace context with only non-null values
     */
    public static function getLogArray(): array
    {
        return array_filter([
            'trace_id' => self::getTraceId(),
            'span_id' => self::getSpanId(),
            'request_id' => self::getRequestId(),
        ], fn ($value) => $value !== null);
    }

    /**
     * Get trace context formatted for logging.
     *
     * Returns a space-separated string with trace context suitable for
     * appending to log messages or using in SQL comments.
     *
     * Example output: "trace=abc123 span=def456 request=xyz789"
     *
     * @return string Formatted trace context (empty string if not available)
     */
    public static function getLogContext(): string
    {
        $parts = [];

        if ($traceId = self::getTraceId()) {
            $parts[] = "trace={$traceId}";
        }
        if ($spanId = self::getSpanId()) {
            $parts[] = "span={$spanId}";
        }
        if ($requestId = self::getRequestId()) {
            $parts[] = "request={$requestId}";
        }

        return implode(' ', $parts);
    }

    /**
     * Get parent span ID (16 hex characters).
     *
     * The parent span ID identifies the span that initiated this operation.
     * Useful for understanding the call hierarchy in distributed traces.
     *
     * @return string|null Parent span ID or null if not available
     */
    public static function getParentSpanId(): ?string
    {
        return self::$request?->getAttribute('trace.parent_span_id');
    }

    /**
     * Get trace context as HTTP headers for propagation.
     *
     * Returns headers suitable for adding to HTTP client requests
     * to propagate trace context to external services.
     *
     * Example:
     * ```php
     * $client->post($url, [
     *     'headers' => TraceContext::getPropagationHeaders()
     * ]);
     * ```
     *
     * @param bool $includeRequestId Whether to include X-Request-Id header
     * @return array<string,string> HTTP headers for trace propagation
     */
    public static function getPropagationHeaders(bool $includeRequestId = true): array
    {
        $headers = [];

        if ($traceparent = self::getTraceparent()) {
            $headers['traceparent'] = $traceparent;
        }

        if ($tracestate = self::getTraceState()) {
            $headers['tracestate'] = $tracestate;
        }

        if ($traceId = self::getTraceId()) {
            $headers['X-Trace-Id'] = $traceId;
        }

        if ($includeRequestId && $requestId = self::getRequestId()) {
            $headers['X-Request-Id'] = $requestId;
        }

        return $headers;
    }

    /**
     * Get the current request (with trace attributes).
     *
     * Useful for advanced scenarios where you need access to the full request.
     * Most code should use specific getter methods instead.
     *
     * @return Request|null Current request or null if not available
     */
    public static function getRequest(): ?Request
    {
        return self::$request;
    }

    /**
     * Get current request ID (32 hex characters).
     *
     * The request ID uniquely identifies a single HTTP request.
     * Use this for:
     * - Request-specific logging
     * - Returning to clients for support tickets
     * - Correlating logs within a single request
     *
     * @return string|null Request ID or null if not available
     */
    public static function getRequestId(): ?string
    {
        return self::$request?->getAttribute('request_id');
    }

    /**
     * Get current span ID (16 hex characters).
     *
     * The span ID uniquely identifies a single operation within a trace.
     * Use this for:
     * - Detailed log correlation
     * - Parent-child span relationships
     * - Operation-level debugging
     *
     * @return string|null Span ID or null if not available
     */
    public static function getSpanId(): ?string
    {
        // In OpenTelemetry mode, try to get from current span context
        if (self::$otelAvailable && self::isOtelSdkAvailable()) {
            try {
                $span = \OpenTelemetry\API\Trace\Span::fromContext(
                    \OpenTelemetry\Context\Context::getCurrent(),
                );
                return $span->getContext()->getSpanId();
            } catch (\Throwable) {
                // Fall through to request attribute
            }
        }

        // Fallback to request attribute
        return self::$request?->getAttribute('trace.span_id');
    }

    /**
     * Get current trace ID (32 hex characters).
     *
     * The trace ID uniquely identifies a distributed trace across services.
     * Use this for:
     * - Log correlation
     * - Database query comments
     * - HTTP header propagation to external services
     * - Error reporting with user-facing trace ID
     *
     * @return string|null Trace ID or null if not available
     */
    public static function getTraceId(): ?string
    {
        // In OpenTelemetry mode, try to get from current span context
        if (self::$otelAvailable && self::isOtelSdkAvailable()) {
            try {
                $context = \OpenTelemetry\API\Globals::propagator()->fields();
                $span = \OpenTelemetry\Context\Context::getCurrent()->get(
                    \OpenTelemetry\API\Trace\Span::fromContext(\OpenTelemetry\Context\Context::getCurrent()),
                );
                if ($span) {
                    return $span->getContext()->getTraceId();
                }
            } catch (\Throwable) {
                // Fall through to request attribute
            }
        }

        // Fallback to request attribute (works in both modes)
        return self::$request?->getAttribute('trace.trace_id');
    }

    /**
     * Get W3C traceparent header value.
     *
     * Format: version-traceid-spanid-flags
     * Example: 00-a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7-1234567890abcdef-01
     *
     * Use this when propagating trace context to external services.
     *
     * @return string|null Traceparent header value or null if not available
     */
    public static function getTraceparent(): ?string
    {
        $traceId = self::getTraceId();
        $spanId = self::getSpanId();
        $flags = self::getFlags() ?? '01';

        if (!$traceId || !$spanId) {
            return null;
        }

        return sprintf('00-%s-%s-%s', $traceId, $spanId, $flags);
    }

    /**
     * Get trace state (vendor-specific context).
     *
     * Tracestate carries vendor-specific trace context across service boundaries.
     * Format: key=value,key=value
     *
     * @return string|null Trace state or null if not available
     */
    public static function getTraceState(): ?string
    {
        return self::$request?->getAttribute('trace.tracestate');
    }

    /**
     * Initialize trace context from request.
     *
     * Called by TelemetryMiddleware/OpenTelemetryHandler automatically.
     * Should not be called directly by application code.
     *
     * @param Request $request Request with trace attributes attached
     * @param bool $otelAvailable Whether OpenTelemetry mode is active
     * @internal
     */
    public static function initialize(Request $request, bool $otelAvailable = false): void
    {
        self::$request = $request;
        self::$otelAvailable = $otelAvailable;
    }

    /**
     * Check if trace context is available.
     *
     * Returns true if TelemetryMiddleware has initialized the context.
     *
     * @return bool True if trace context is available
     */
    public static function isAvailable(): bool
    {
        return self::$request !== null;
    }

    /**
     * Check if OpenTelemetry mode is active.
     *
     * Returns true if OpenTelemetry SDK is available and middleware
     * is using full span management.
     *
     * @return bool True if OpenTelemetry mode is active
     */
    public static function isOtelMode(): bool
    {
        return self::$otelAvailable;
    }

    /**
     * Check if request is being sampled.
     *
     * Returns true if the trace is being recorded (sampled).
     * Useful for conditionally adding expensive debug information.
     *
     * @return bool True if trace is being sampled
     */
    public static function isSampled(): bool
    {
        $flags = self::getFlags();
        if (!$flags) {
            return false;
        }

        // Flags format: 2 hex chars, least significant bit = sampled flag
        $flagsInt = hexdec($flags);
        return ($flagsInt & 0x01) === 0x01;
    }

    /**
     * Check if OpenTelemetry SDK classes are available.
     *
     * @internal
     */
    private static function isOtelSdkAvailable(): bool
    {
        return class_exists('OpenTelemetry\\API\\Globals')
            && class_exists('OpenTelemetry\\Context\\Context')
            && class_exists('OpenTelemetry\\API\\Trace\\Span');
    }
}
