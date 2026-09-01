<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/*
|--------------------------------------------------------------------------
| Test Case (Optional - only if needed)
|--------------------------------------------------------------------------
*/
// Most tests don't need a TestCase, but it's available if you want it
// uses(Tests\TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toHaveStatus', function (int $status) {
    $actual = $this->value->getStatusCode();
    expect($actual)->toBe($status, "Expected status {$status}, got {$actual}");

    return $this;
});

expect()->extend('toHaveHeader', function (string $header) {
    expect($this->value->hasHeader($header))->toBeTrue("Expected header '{$header}' to be present");

    return $this;
});

/*
|--------------------------------------------------------------------------
| Telemetry & Trace Context Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeTraceId', function () {
    expect($this->value)
        ->toBeString('Trace ID must be a string')
        ->toHaveLength(32, 'Trace ID must be 32 characters')
        ->and(ctype_xdigit($this->value))->toBeTrue('Trace ID must be hexadecimal');

    return $this;
});

expect()->extend('toBeSpanId', function () {
    expect($this->value)
        ->toBeString('Span ID must be a string')
        ->toHaveLength(16, 'Span ID must be 16 characters')
        ->and(ctype_xdigit($this->value))->toBeTrue('Span ID must be hexadecimal');

    return $this;
});

expect()->extend('toBeRequestId', function () {
    expect($this->value)
        ->toBeString('Request ID must be a string')
        ->toHaveLength(32, 'Request ID must be 32 characters')
        ->and(ctype_xdigit($this->value))->toBeTrue('Request ID must be hexadecimal');

    return $this;
});

expect()->extend('toBeValidTraceparent', function () {
    expect($this->value)
        ->toBeString('Traceparent must be a string')
        ->toMatch('/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/', 'Traceparent must match W3C format: 00-{trace-id}-{span-id}-{flags}');

    return $this;
});

expect()->extend('toHaveTelemetryHeaders', function () {
    expect($this->value->hasHeader('X-Response-Time'))->toBeTrue('Expected X-Response-Time header');
    expect($this->value->hasHeader('X-Request-Id'))->toBeTrue('Expected X-Request-Id header');
    expect($this->value->hasHeader('Trace-Id'))->toBeTrue('Expected Trace-Id header');

    return $this;
});

expect()->extend('toHaveResponseTimeHeader', function () {
    expect($this->value->hasHeader('X-Response-Time'))->toBeTrue('Expected X-Response-Time header');
    $timing = $this->value->getHeaderLine('X-Response-Time');
    expect($timing)->toMatch('/^\d+\.\d+ms$/', 'X-Response-Time must be in format: 45.2ms');

    return $this;
});

expect()->extend('toHaveServerTimingHeader', function () {
    expect($this->value->hasHeader('Server-Timing'))->toBeTrue('Expected Server-Timing header');
    $timing = $this->value->getHeaderLine('Server-Timing');
    expect($timing)->toMatch('/^app;dur=\d+\.\d+$/', 'Server-Timing must be in format: app;dur=45.2');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Create a mock request with trace attributes.
 */
function mockRequestWithTrace(
    string $method = 'GET',
    string $uri = '/',
    ?string $traceId = null,
    ?string $spanId = null,
    ?string $requestId = null,
    array $headers = []
): Request {
    $request = mockRequest($method, $uri, $headers);

    if ($traceId) {
        $request = $request->withAttribute('trace.trace_id', $traceId);
    }
    if ($spanId) {
        $request = $request->withAttribute('trace.span_id', $spanId);
    }
    if ($requestId) {
        $request = $request->withAttribute('request_id', $requestId);
    }

    return $request;
}

/**
 * Assert that response has telemetry headers.
 */
function assertHasTelemetryHeaders(Response $response): void
{
    expect($response->hasHeader('X-Response-Time'))
        ->toBeTrue('Expected X-Response-Time header')
        ->and($response->hasHeader('X-Request-Id'))->toBeTrue('Expected X-Request-Id header')
        ->and($response->hasHeader('Trace-Id'))->toBeTrue('Expected Trace-Id header');
}

/**
 * Extract trace context from response body (assumes JSON).
 */
function extractTraceContext(Response $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    return [
        'trace_id' => $data['trace_id'] ?? null,
        'span_id' => $data['span_id'] ?? null,
        'request_id' => $data['request_id'] ?? null,
    ];
}

/**
 * Generate a valid W3C trace ID (32 hex chars).
 */
function generateTraceId(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Generate a valid W3C span ID (16 hex chars).
 */
function generateSpanId(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * Generate a valid request ID (32 hex chars).
 */
function generateRequestId(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Build a W3C traceparent header.
 */
function buildTraceparent(string $traceId, string $spanId, string $flags = '01'): string
{
    return sprintf('00-%s-%s-%s', $traceId, $spanId, $flags);
}
